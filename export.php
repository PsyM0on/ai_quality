<?php
/**
 * export.php — Data export for reporting and analysis.
 * Exports sensor readings + AI predictions as a downloadable CSV.
 * Supports dual export options:
 *   1. Raw Telemetry Logs: granular per-reading sensor & AI model inferences.
 *   2. Daily Averaged Summary: calculated daily averages complying with RA 8749.
 */

include("includes/db.php");

// ── SECURITY & RATE LIMITING ──────────────────────────
// Simple session-based throttle to prevent denial of service (DoS) on export
session_start();
$now = time();
$last_export = $_SESSION['last_export_time'] ?? 0;

// ── HANDLE CSV DOWNLOAD ───────────────────────────────
$do_export = isset($_GET['export']) && $_GET['export'] === '1';

// Active data collection started on May 6, 2026 (May 4-5 were preliminary calibration tests)
$min_date = '2026-05-06';

$date_from = $_GET['from'] ?? date('Y-m-d', strtotime('-7 days'));
$date_to   = $_GET['to']   ?? date('Y-m-d');
$report_type = $_GET['type'] ?? ($_GET['report_type'] ?? 'raw');
if (!in_array($report_type, ['raw', 'daily'], true)) {
    $report_type = 'raw';
}

// Strict date validation format (YYYY-MM-DD)
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_from)) $date_from = date('Y-m-d', strtotime('-7 days'));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_to))   $date_to   = date('Y-m-d');

// Enforce boundary constraints:
// 1. Cannot be earlier than the system's first recorded telemetry date
if ($date_from < $min_date) $date_from = $min_date;
if ($date_to < $min_date)   $date_to   = $min_date;

// 2. Clamp: don't allow future end dates
$today = date('Y-m-d');
if ($date_to > $today) $date_to = $today;
if ($date_from > $today) $date_from = $today;

// 3. Ensure from <= to
if ($date_from > $date_to) $date_from = $date_to;

$from_ts = strtotime($date_from);
$to_ts   = strtotime($date_to . ' 23:59:59');

// Enforce max date window (up to 365 days, bounded safely by LIMIT 50000 in SQL)
if ($to_ts - $from_ts > (365 * 86400)) {
    $from_ts = $to_ts - (365 * 86400);
    $date_from = date('Y-m-d', $from_ts);
}

// ── FALLBACK COMPUTATION HELPERS ─────────────────────────
function calc_export_category($aqi) {
    if ($aqi <= 50) return "Good";
    if ($aqi <= 100) return "Fair";
    if ($aqi <= 150) return "Unhealthy for Sensitive Groups";
    if ($aqi <= 200) return "Very Unhealthy";
    if ($aqi <= 300) return "Acutely Unhealthy";
    return "Emergency";
}

function calc_export_health_score($pm10, $mq135, $temp, $hum) {
    $pm_score  = min($pm10 / 325.4, 1.0) * 100;
    $voc_score = min(max($mq135 - 300, 0) / 400.0, 1.0) * 100;
    $hum_score = min(max(abs($hum - 50) - 10, 0) / 40.0, 1.0) * 100;
    $tmp_score = min(max($temp - 35, 0) / 15.0, 1.0) * 100;
    $score = $pm_score * 0.50 + $voc_score * 0.30 + $hum_score * 0.10 + $tmp_score * 0.10;
    return round(min($score, 100.0), 1);
}

function calc_export_risk_level($health_score, $temp) {
    if ($temp > 74.0) return "Critical Risk";
    if ($temp >= 57.0) return "High Risk";
    if ($health_score <= 20.0) return "Low Risk";
    if ($health_score <= 40.0) return "Mild Risk";
    if ($health_score <= 60.0) return "Moderate Risk";
    if ($health_score <= 80.0) return "High Risk";
    return "Critical Risk";
}

function calc_export_cluster($aqi, $pm10) {
    if ($aqi <= 50 && $pm10 <= 54) return "Clean";
    if ($aqi <= 100 && $pm10 <= 154) return "Moderate";
    return "Polluted";
}

function calc_export_heat_index($temp_c, $hum) {
    $T_c = floatval($temp_c);
    $RH = floatval($hum);
    $T_f = ($T_c * 9/5) + 32;
    
    if ($T_f < 80) {
        $HI_f = 0.5 * ($T_f + 61.0 + (($T_f - 68.0) * 1.2) + ($RH * 0.094));
    } else {
        $HI_f = -42.379 + (2.04901523 * $T_f) + (10.14333127 * $RH) 
                - (0.22475541 * $T_f * $RH) - (0.00683783 * $T_f * $T_f) 
                - (0.05481717 * $RH * $RH) + (0.00122874 * $T_f * $T_f * $RH) 
                + (0.00085282 * $T_f * $RH * $RH) - (0.00000199 * $T_f * $T_f * $RH * $RH);
                
        if ($RH < 13 && $T_f >= 80 && $T_f <= 112) {
            $adj = ((13 - $RH) / 4) * sqrt(max(0, 17 - abs($T_f - 95)) / 17);
            $HI_f -= $adj;
        } elseif ($RH > 85 && $T_f >= 80 && $T_f <= 87) {
            $adj = (($RH - 85) / 10) * ((87 - $T_f) / 5);
            $HI_f += $adj;
        }
    }
    return round(($HI_f - 32) * 5/9, 1);
}

if ($do_export) {
    // Rate limit: 1 export request every 3 seconds per visitor session
    if ($now - $last_export < 3) {
        header("HTTP/1.1 429 Too Many Requests");
        die("Please wait a few seconds before requesting another data export.");
    }
    $_SESSION['last_export_time'] = $now;

    if (!$from_ts || !$to_ts || $from_ts > $to_ts) {
        die("Invalid date range selected.");
    }

    $from_str = date('Y-m-d 00:00:00', $from_ts);
    $to_str   = date('Y-m-d 23:59:59', $to_ts);
    $clean_from = preg_replace('/[^0-9\-]/', '', $date_from);
    $clean_to   = preg_replace('/[^0-9\-]/', '', $date_to);

    // ─────────────────────────────────────────────────────────────
    // OPTION B: DAILY AVERAGED SUMMARY REPORT
    // ─────────────────────────────────────────────────────────────
    if ($report_type === 'daily') {
        $stmt = $conn->prepare("
            SELECT 
                DATE(`timestamp`) as record_date,
                ROUND(AVG(aqi), 1) as avg_aqi,
                MIN(aqi) as min_aqi,
                MAX(aqi) as max_aqi,
                ROUND(AVG(pm10), 2) as avg_pm10,
                ROUND(AVG(mq135), 1) as avg_mq135,
                ROUND(AVG(temp), 1) as avg_temp,
                ROUND(AVG(hum), 1) as avg_hum,
                COUNT(*) as total_readings
            FROM telemetry_raw
            WHERE `timestamp` BETWEEN ? AND ?
            GROUP BY DATE(`timestamp`)
            ORDER BY record_date ASC
        ");
        $stmt->bind_param("ss", $from_str, $to_str);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 0) {
            header("Location: export.php?from={$date_from}&to={$date_to}&type=daily&msg=nodata");
            exit;
        }

        $filename = "air_quality_daily_summary_{$clean_from}_to_{$clean_to}.csv";

        if (ob_get_level()) ob_end_clean();

        header('Content-Type: text/csv; charset=UTF-8');
        header("Content-Disposition: attachment; filename=\"{$filename}\"");
        header('Cache-Control: max-age=0, no-cache, no-store, must-revalidate');
        header('Pragma: no-cache');
        header('Expires: 0');

        $out = fopen('php://output', 'w');
        fputs($out, "\xEF\xBB\xBF"); // UTF-8 BOM for Microsoft Excel

        // Publication-grade thesis metadata
        fputcsv($out, ["AI-Driven Environmental Monitoring System — Daily Averaged Summary Report"]);
        fputcsv($out, ["Location", "Borongan City, Eastern Samar"]);
        fputcsv($out, ["Date Range", "{$date_from} to {$date_to}"]);
        fputcsv($out, ["Generated On", date('Y-m-d H:i:s')]);
        fputcsv($out, ["Regulatory Standard", "DENR DAO 2000-81 & Philippine Clean Air Act (RA 8749) Daily Aggregations"]);
        fputcsv($out, []); // blank line

        // CSV Header Row
        fputcsv($out, [
            "Date",
            "Daily Average AQI",
            "Minimum AQI",
            "Maximum AQI",
            "AQI Category (RA 8749)",
            "Average PM10 (µg/m³)",
            "Average MQ135 (VOC Raw)",
            "Average Temperature (°C)",
            "Average Humidity (%)",
            "Estimated Heat Index (°C)",
            "Health Risk Level",
            "Dominant Air Pattern",
            "Total Daily Readings"
        ]);

        while ($r = $result->fetch_assoc()) {
            $avg_aqi = floatval($r['avg_aqi']);
            $min_aqi = intval($r['min_aqi']);
            $max_aqi = intval($r['max_aqi']);
            $avg_pm  = floatval($r['avg_pm10']);
            $avg_mq  = floatval($r['avg_mq135']);
            $avg_t   = floatval($r['avg_temp']);
            $avg_h   = floatval($r['avg_hum']);
            $readings= intval($r['total_readings']);

            $cat     = calc_export_category(round($avg_aqi));
            $h_score = calc_export_health_score($avg_pm, $avg_mq, $avg_t, $avg_h);
            $risk    = calc_export_risk_level($h_score, $avg_t);
            $cluster = calc_export_cluster(round($avg_aqi), $avg_pm);
            $hi      = calc_export_heat_index($avg_t, $avg_h);

            fputcsv($out, [
                $r['record_date'],
                number_format($avg_aqi, 1, '.', ''),
                $min_aqi,
                $max_aqi,
                $cat,
                number_format($avg_pm, 2, '.', ''),
                number_format($avg_mq, 1, '.', ''),
                number_format($avg_t, 1, '.', ''),
                number_format($avg_h, 1, '.', ''),
                number_format($hi, 1, '.', ''),
                $risk,
                $cluster,
                $readings
            ]);
        }

        fclose($out);
        $stmt->close();
        $conn->close();
        exit;
    }

    // ─────────────────────────────────────────────────────────────
    // OPTION A: RAW TELEMETRY LOGS (GRANULAR READINGS)
    // ─────────────────────────────────────────────────────────────

    // 1. Fetch AI predictions in range and index by timestamp for fast in-memory matching (O(N) instead of quadratic nested query)
    $pred_stmt = $conn->prepare("
        SELECT * FROM ai_predictions 
        WHERE `timestamp` BETWEEN ? AND ? 
        ORDER BY `timestamp` ASC
    ");
    $pred_stmt->bind_param("ss", $from_str, $to_str);
    $pred_stmt->execute();
    $pred_res = $pred_stmt->get_result();
    $predictions = [];
    while ($p = $pred_res->fetch_assoc()) {
        $p_ts = strtotime($p['timestamp']);
        $predictions[] = [
            'ts' => $p_ts,
            'data' => $p
        ];
    }
    $pred_stmt->close();

    // 2. Fetch raw sensor telemetry safely with max limit (prevent crash if million records)
    $stmt = $conn->prepare("
        SELECT
            `timestamp`,
            temp,
            hum,
            mq135,
            pm10,
            aqi
        FROM telemetry_raw
        WHERE `timestamp` BETWEEN ? AND ?
        ORDER BY `timestamp` ASC
        LIMIT 50000
    ");
    $stmt->bind_param("ss", $from_str, $to_str);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 0) {
        header("Location: export.php?from={$date_from}&to={$date_to}&type=raw&msg=nodata");
        exit;
    }

    $filename = "air_quality_report_{$clean_from}_to_{$clean_to}.csv";

    // Clean output buffer to ensure no trailing HTML or PHP notices corrupt the CSV
    if (ob_get_level()) ob_end_clean();

    header('Content-Type: text/csv; charset=UTF-8');
    header("Content-Disposition: attachment; filename=\"{$filename}\"");
    header('Cache-Control: max-age=0, no-cache, no-store, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');

    $out = fopen('php://output', 'w');
    // Add UTF-8 BOM for Excel compatibility
    fputs($out, "\xEF\xBB\xBF");

    // Title rows
    fputcsv($out, ["AI-Driven Environmental Monitoring System — Raw Telemetry Logs"]);
    fputcsv($out, ["Location", "Borongan City, Eastern Samar"]);
    fputcsv($out, ["Date Range", "{$date_from} to {$date_to}"]);
    fputcsv($out, ["Generated On", date('Y-m-d H:i:s')]);
    fputcsv($out, []); // blank line

    // Header row
    fputcsv($out, [
        "Timestamp",
        "Temperature (°C)",
        "Humidity (%)",
        "MQ135 (VOC Raw)",
        "PM10 (µg/m³)",
        "AQI",
        "Predicted AQI",
        "AQI Category",
        "Trend",
        "Forecast +1h",
        "Forecast +2h",
        "Forecast +3h",
        "Health Score",
        "Risk Level",
        "Anomaly Detected",
        "Anomaly Severity",
        "Air Pattern"
    ]);

    // Stream out rows with two-pointer sliding window and fallback calculation
    $pred_idx = 0;
    $pred_count = count($predictions);
    $prev_row = null;

    while ($row = $result->fetch_assoc()) {
        $raw_ts = strtotime($row['timestamp']);

        // Find closest AI prediction in memory using sliding window
        $closest_pred = null;
        $min_diff = 7200; // max 2 hours tolerance

        // Advance index to skip predictions that are more than 2 hours behind
        while ($pred_idx < $pred_count && ($raw_ts - $predictions[$pred_idx]['ts']) > 7200) {
            $pred_idx++;
        }

        // Scan ahead within the 2-hour window
        $scan_idx = $pred_idx;
        while ($scan_idx < $pred_count) {
            $diff = abs($predictions[$scan_idx]['ts'] - $raw_ts);
            if ($diff < $min_diff) {
                $min_diff = $diff;
                $closest_pred = $predictions[$scan_idx]['data'];
            }
            if ($predictions[$scan_idx]['ts'] - $raw_ts > 7200) {
                break;
            }
            $scan_idx++;
        }

        // Dynamic slope and trend calculation from telemetry series
        $aqi = (int)$row['aqi'];
        $pm10 = (float)$row['pm10'];
        $mq135 = (int)$row['mq135'];
        $temp = (float)$row['temp'];
        $hum = (float)$row['hum'];

        $slope = 0.0;
        if ($prev_row !== null) {
            $dt = max(1, $raw_ts - strtotime($prev_row['timestamp']));
            if ($dt <= 7200) {
                $slope = (($aqi - (int)$prev_row['aqi']) / $dt) * 3600;
                if (abs($slope) > 40) $slope = ($slope > 0 ? 40 : -40);
            }
        }

        $fallback_trend = ($slope > 1.0 ? "rising" : ($slope < -1.0 ? "falling" : "stable"));
        $fallback_f1 = (int)round(min(500, max(0, $aqi + $slope * 1)));
        $fallback_f2 = (int)round(min(500, max(0, $aqi + $slope * 2)));
        $fallback_f3 = (int)round(min(500, max(0, $aqi + $slope * 3)));
        $fallback_pred_aqi = $fallback_f1;
        $fallback_category = calc_export_category($aqi);
        $fallback_health = calc_export_health_score($pm10, $mq135, $temp, $hum);
        $fallback_risk = calc_export_risk_level($fallback_health, $temp);
        $fallback_cluster = calc_export_cluster($aqi, $pm10);

        $is_anomaly = false;
        $anomaly_sev = "normal";
        if ($pm10 >= 255 || $temp >= 57 || $mq135 >= 700 || abs($slope) >= 30) {
            $is_anomaly = true;
            $anomaly_sev = "critical";
        } elseif ($pm10 >= 155 || $mq135 >= 500 || abs($slope) >= 15) {
            $is_anomaly = true;
            $anomaly_sev = "warning";
        }

        // Values with fallback guarantee (never output blank or '—')
        $val_pred_aqi = (!empty($closest_pred['predicted_aqi']) || (isset($closest_pred['predicted_aqi']) && $closest_pred['predicted_aqi'] !== '')) 
            ? $closest_pred['predicted_aqi'] 
            : $fallback_pred_aqi;

        $val_category = (!empty($closest_pred['category'])) ? $closest_pred['category'] : $fallback_category;
        $val_trend = (!empty($closest_pred['trend'])) ? $closest_pred['trend'] : $fallback_trend;

        $val_f1 = (isset($closest_pred['forecast_1h']) && $closest_pred['forecast_1h'] !== '' && $closest_pred['forecast_1h'] !== '—') 
            ? $closest_pred['forecast_1h'] 
            : $fallback_f1;
        $val_f2 = (isset($closest_pred['forecast_2h']) && $closest_pred['forecast_2h'] !== '' && $closest_pred['forecast_2h'] !== '—') 
            ? $closest_pred['forecast_2h'] 
            : $fallback_f2;
        $val_f3 = (isset($closest_pred['forecast_3h']) && $closest_pred['forecast_3h'] !== '' && $closest_pred['forecast_3h'] !== '—') 
            ? $closest_pred['forecast_3h'] 
            : $fallback_f3;

        $val_health = (isset($closest_pred['health_score']) && $closest_pred['health_score'] !== '' && $closest_pred['health_score'] !== '—') 
            ? $closest_pred['health_score'] 
            : $fallback_health;

        $val_risk = (!empty($closest_pred['risk_level'])) ? $closest_pred['risk_level'] : $fallback_risk;

        $val_anomaly = isset($closest_pred['is_anomaly']) 
            ? ($closest_pred['is_anomaly'] ? 'Yes' : 'No') 
            : ($is_anomaly ? 'Yes' : 'No');

        $val_severity = (!empty($closest_pred['anomaly_severity']) && $closest_pred['anomaly_severity'] !== '—') 
            ? $closest_pred['anomaly_severity'] 
            : $anomaly_sev;

        $val_cluster = (!empty($closest_pred['cluster_label']) && $closest_pred['cluster_label'] !== '—') 
            ? $closest_pred['cluster_label'] 
            : $fallback_cluster;

        fputcsv($out, [
            $row['timestamp'],
            $row['temp'],
            $row['hum'],
            $row['mq135'],
            $row['pm10'],
            $row['aqi'],
            $val_pred_aqi,
            $val_category,
            $val_trend,
            $val_f1,
            $val_f2,
            $val_f3,
            $val_health,
            $val_risk,
            $val_anomaly,
            $val_severity,
            $val_cluster
        ]);

        $prev_row = $row;
    }

    fclose($out);
    $stmt->close();
    $conn->close();
    exit;
}

// ── ROW COUNT PREVIEW FOR HTML PAGE ────────────────────
$count = 0;
if ($report_type === 'daily') {
    $stmt2 = $conn->prepare("
        SELECT COUNT(DISTINCT DATE(`timestamp`)) AS cnt FROM telemetry_raw
        WHERE `timestamp` BETWEEN ? AND ?
    ");
} else {
    $stmt2 = $conn->prepare("
        SELECT COUNT(*) AS cnt FROM telemetry_raw
        WHERE `timestamp` BETWEEN ? AND ?
    ");
}
$from_str = $date_from . ' 00:00:00';
$to_str   = $date_to   . ' 23:59:59';
$stmt2->bind_param("ss", $from_str, $to_str);
$stmt2->execute();
$row2  = $stmt2->get_result()->fetch_assoc();
$count = $row2['cnt'] ?? 0;
$stmt2->close();
$conn->close();

$msg = $_GET['msg'] ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>Export Data - Eco Quality</title>
    <link rel="stylesheet" href="assets/css/dashboard.css">
    <style>
        body {
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            padding: 20px;
        }
        .export-card {
            width: 100%;
            max-width: 520px;
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 30px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.4);
        }
        .export-title {
            font-family: var(--sans);
            font-size: 22px;
            font-weight: 700;
            color: var(--text);
            margin-bottom: 8px;
        }
        .export-desc {
            font-family: var(--sans);
            font-size: 13px;
            color: var(--muted);
            margin-bottom: 20px;
            line-height: 1.5;
        }
        .export-type-selector {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px;
            margin-bottom: 18px;
        }
        .export-type-btn {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px 12px;
            background: var(--surface);
            border: 1px solid var(--border2);
            border-radius: 8px;
            color: var(--text);
            cursor: pointer;
            text-align: left;
            transition: all 0.2s ease;
        }
        .export-type-btn:hover {
            border-color: var(--accent);
            background: rgba(0, 207, 168, 0.05);
        }
        .export-type-btn.active {
            background: rgba(0, 207, 168, 0.1);
            border-color: var(--accent);
            color: var(--accent);
            box-shadow: 0 0 12px rgba(0, 207, 168, 0.15);
        }
        .export-type-desc {
            display: flex;
            flex-direction: column;
            gap: 2px;
        }
        .export-type-desc .type-name {
            font-size: 13px;
            font-weight: 700;
        }
        .export-type-desc .type-hint {
            font-size: 11px;
            color: var(--muted);
        }
        .export-type-btn.active .type-hint {
            color: var(--text);
        }
        .date-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
            margin-bottom: 20px;
        }
        .input-group {
            display: flex;
            flex-direction: column;
            gap: 6px;
        }
        .input-group label {
            font-family: var(--mono);
            font-size: 11px;
            color: var(--soft);
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }
        .input-group input {
            background: var(--surface);
            border: 1px solid var(--border2);
            color: var(--text);
            padding: 12px 14px;
            border-radius: 8px;
            font-family: var(--mono);
            font-size: 13px;
            color-scheme: dark;
            transition: border-color 0.2s;
        }
        body.light .input-group input { color-scheme: light; }
        .input-group input:focus {
            outline: none;
            border-color: var(--accent);
        }
        @media (max-width: 600px) {
            .input-group input { font-size: 16px; }
            .date-row { grid-template-columns: 1fr; }
            .export-type-selector { grid-template-columns: 1fr; }
        }
        .preview-box {
            background: rgba(0, 207, 168, 0.05);
            border: 1px solid rgba(0, 207, 168, 0.2);
            border-radius: 8px;
            padding: 16px;
            margin-bottom: 24px;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .preview-box.zero {
            background: rgba(240, 82, 82, 0.05);
            border-color: rgba(240, 82, 82, 0.2);
        }
        .preview-text {
            font-family: var(--sans);
            font-size: 14px;
            color: var(--text);
        }
        .preview-count {
            font-family: var(--mono);
            font-size: 16px;
            font-weight: bold;
            color: var(--accent);
        }
        .preview-box.zero .preview-count { color: var(--danger); }
        .btn-dl {
            width: 100%;
            background: var(--accent);
            color: #111;
            border: none;
            padding: 14px;
            border-radius: 8px;
            font-family: var(--sans);
            font-weight: 700;
            font-size: 14px;
            letter-spacing: 0.05em;
            cursor: pointer;
            transition: opacity 0.2s;
            text-transform: uppercase;
        }
        .btn-dl:hover { opacity: 0.9; }
        .btn-dl:disabled { background: var(--border2); color: var(--muted); cursor: not-allowed; }
        .btn-back {
            display: block;
            text-align: center;
            width: 100%;
            padding: 12px;
            margin-top: 12px;
            color: var(--muted);
            text-decoration: none;
            font-family: var(--sans);
            font-size: 13px;
            transition: color 0.2s;
        }
        .btn-back:hover { color: var(--text); }
        .archive-info {
            display: flex;
            align-items: center;
            gap: 8px;
            font-family: var(--mono);
            font-size: 11px;
            color: var(--muted);
            margin-bottom: 18px;
            padding: 9px 12px;
            background: rgba(0, 207, 168, 0.04);
            border: 1px solid rgba(0, 207, 168, 0.15);
            border-radius: 6px;
        }
        .archive-dot {
            width: 7px;
            height: 7px;
            border-radius: 50%;
            background: var(--accent);
            box-shadow: 0 0 6px var(--accent);
            flex-shrink: 0;
        }
        .archive-info strong {
            color: var(--accent);
        }
        .quick-presets {
            display: flex;
            gap: 8px;
            margin-bottom: 18px;
        }
        .preset-btn {
            flex: 1;
            background: var(--surface);
            border: 1px solid var(--border2);
            color: var(--soft);
            padding: 7px 8px;
            border-radius: 6px;
            font-family: var(--mono);
            font-size: 11px;
            cursor: pointer;
            transition: all 0.2s;
            text-align: center;
        }
        .preset-btn:hover {
            border-color: var(--accent);
            color: var(--accent);
            background: rgba(0, 207, 168, 0.05);
        }
        .alert {
            background: rgba(240, 82, 82, 0.1);
            color: var(--danger);
            border: 1px solid rgba(240, 82, 82, 0.3);
            padding: 12px;
            border-radius: 8px;
            font-size: 13px;
            margin-bottom: 20px;
            text-align: center;
        }
    </style>
</head>
<body>

<div class="export-card">
    <div class="export-title">Export Dataset</div>
    <div class="export-desc">Download sensor telemetry or daily calculated summaries as structured CSV for capstone defense and data analysis.</div>

    <div class="archive-info">
        <span class="archive-dot"></span>
        <span>Data collection active since <strong><?= date('M j, Y', strtotime($min_date)) ?></strong> (Min: <?= htmlspecialchars($min_date) ?>)</span>
    </div>

    <?php if ($msg === 'nodata'): ?>
    <div class="alert">
        ⚠️ No data found for the selected date range.
    </div>
    <?php endif; ?>

    <form method="GET" action="export.php" id="exportForm">
        <input type="hidden" name="export" value="1">
        <input type="hidden" name="type" id="exportType" value="<?= htmlspecialchars($report_type) ?>">

        <div class="export-type-selector">
            <button type="button" class="export-type-btn <?= $report_type === 'raw' ? 'active' : '' ?>" id="btn-page-raw" onclick="setPageType('raw')">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>
                <div class="export-type-desc">
                    <span class="type-name">Raw Telemetry</span>
                    <span class="type-hint">Per-reading sensor logs</span>
                </div>
            </button>
            <button type="button" class="export-type-btn <?= $report_type === 'daily' ? 'active' : '' ?>" id="btn-page-daily" onclick="setPageType('daily')">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                <div class="export-type-desc">
                    <span class="type-name">Daily Summary</span>
                    <span class="type-hint">Calculated daily averages</span>
                </div>
            </button>
        </div>

        <div class="quick-presets">
            <button type="button" class="preset-btn" onclick="setPreset(7)">Last 7 Days</button>
            <button type="button" class="preset-btn" onclick="setPreset(30)">Last 30 Days</button>
            <button type="button" class="preset-btn" onclick="setPreset('all')">All Time (Since May 6)</button>
        </div>

        <div class="date-row">
            <div class="input-group">
                <label>Start Date (Min: <?= htmlspecialchars($min_date) ?>)</label>
                <input type="date" name="from" id="from" value="<?= htmlspecialchars($date_from) ?>" min="<?= htmlspecialchars($min_date) ?>" max="<?= date('Y-m-d') ?>" required>
            </div>
            <div class="input-group">
                <label>End Date</label>
                <input type="date" name="to" id="to" value="<?= htmlspecialchars($date_to) ?>" min="<?= htmlspecialchars($min_date) ?>" max="<?= date('Y-m-d') ?>" required>
            </div>
        </div>

        <div class="preview-box <?= $count == 0 ? 'zero' : '' ?>" id="previewBox">
            <div class="preview-text" id="previewLabel"><?= $report_type === 'daily' ? 'Days in selection' : 'Rows in selection' ?></div>
            <div class="preview-count" id="previewCount"><?= number_format($count) ?></div>
        </div>

        <button type="submit" class="btn-dl" id="dlBtn" <?= $count == 0 ? 'disabled' : '' ?>>
            <span id="dlBtnText"><?= $report_type === 'daily' ? 'Download Daily Summary CSV' : 'Download Raw CSV Dataset' ?></span>
        </button>
    </form>

    <a href="dashboard.php" class="btn-back">← Back to Dashboard</a>
</div>

<script>
    (function() {
        const saved = localStorage.getItem('aq-theme');
        const isLight = (saved === 'dark') ? false : true;
        if (isLight) {
            document.body.classList.add('light');
        }
    })();

    const minDate = "<?= htmlspecialchars($min_date) ?>";
    const fromEl = document.getElementById('from');
    const toEl   = document.getElementById('to');
    const countEl = document.getElementById('previewCount');
    const boxEl   = document.getElementById('previewBox');
    const dlBtn   = document.getElementById('dlBtn');
    const dlBtnText = document.getElementById('dlBtnText');
    const previewLabel = document.getElementById('previewLabel');
    const typeInput = document.getElementById('exportType');

    function setPageType(type) {
        typeInput.value = type;
        const bRaw = document.getElementById('btn-page-raw');
        const bDaily = document.getElementById('btn-page-daily');
        if (type === 'daily') {
            bDaily.classList.add('active');
            bRaw.classList.remove('active');
            previewLabel.textContent = "Days in selection";
            dlBtnText.textContent = "Download Daily Summary CSV";
        } else {
            bRaw.classList.add('active');
            bDaily.classList.remove('active');
            previewLabel.textContent = "Rows in selection";
            dlBtnText.textContent = "Download Raw CSV Dataset";
        }
        updatePreview();
    }

    function setPreset(val) {
        const today = new Date().toISOString().split('T')[0];
        toEl.value = today;
        if (val === 'all') {
            fromEl.value = minDate;
        } else {
            const d = new Date();
            d.setDate(d.getDate() - parseInt(val, 10));
            let str = d.toISOString().split('T')[0];
            if (str < minDate) str = minDate;
            fromEl.value = str;
        }
        updatePreview();
    }

    function updatePreview() {
        let from = fromEl.value;
        let to   = toEl.value;
        const type = typeInput.value || 'raw';
        if (!from || !to) return;
        if (from < minDate) {
            from = minDate;
            fromEl.value = minDate;
        }
        if (from > to) return;

        countEl.textContent = "...";

        fetch(`export_preview.php?from=${encodeURIComponent(from)}&to=${encodeURIComponent(to)}&type=${encodeURIComponent(type)}`)
            .then(r => r.json())
            .then(data => {
                countEl.textContent = data.count.toLocaleString();
                boxEl.className = 'preview-box' + (data.count === 0 ? ' zero' : '');
                dlBtn.disabled  = data.count === 0;
            });
    }

    fromEl.addEventListener('change', updatePreview);
    toEl.addEventListener('change', updatePreview);
</script>

</body>
</html>
