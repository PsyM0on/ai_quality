<?php
/**
 * ai/backfill_predictions.php
 * 
 * Backfills the `ai_predictions` table for any `telemetry_raw` rows
 * that do not have a matching prediction record.
 * Uses exact Philippine RA 8749 Clean Air Act standards, project health risk weights,
 * linear trend forecasting, and K-Means environmental pattern definitions.
 */

$root = dirname(__DIR__);
require_once $root . "/includes/db.php";

echo "=== AI PREDICTIONS BACKFILL UTILITY ===\n";

// Helper functions matching ai/utils.py and RA 8749 standards
function backfill_category($aqi) {
    if ($aqi <= 50) return "Good";
    if ($aqi <= 100) return "Fair";
    if ($aqi <= 150) return "Unhealthy for Sensitive Groups";
    if ($aqi <= 200) return "Very Unhealthy";
    if ($aqi <= 300) return "Acutely Unhealthy";
    return "Emergency";
}

function backfill_advice($aqi) {
    if ($aqi <= 50) return "Air quality is satisfactory. No air pollution health risks (DENR Good).";
    if ($aqi <= 100) return "Air quality is acceptable (Fair). Unusually sensitive individuals should consider limiting prolonged outdoor exertion.";
    if ($aqi <= 150) return "Unhealthy for Sensitive Groups. People with respiratory or heart disease, the elderly, and children should limit outdoor exertion.";
    if ($aqi <= 200) return "Very Unhealthy. People with respiratory illness should avoid outdoor exertion; everyone else should limit prolonged exposure.";
    if ($aqi <= 300) return "Acutely Unhealthy. People with respiratory disease (asthma) must stay indoors; general public should avoid outdoor exertion.";
    return "EMERGENCY. Everyone should avoid outdoor exertion; remain indoors with doors and windows closed.";
}

function backfill_health_score($pm10, $mq135, $temp, $hum) {
    $pm_score  = min($pm10 / 325.4, 1.0) * 100;
    $voc_score = min(max($mq135 - 300, 0) / 400.0, 1.0) * 100;
    $hum_score = min(max(abs($hum - 50) - 10, 0) / 40.0, 1.0) * 100;
    $tmp_score = min(max($temp - 35, 0) / 15.0, 1.0) * 100;
    $score = $pm_score * 0.50 + $voc_score * 0.30 + $hum_score * 0.10 + $tmp_score * 0.10;
    return round(min($score, 100.0), 1);
}

function backfill_risk_level($health_score, $temp) {
    if ($temp > 74.0) return "Critical Risk";
    if ($temp >= 57.0) return "High Risk";
    if ($health_score <= 20.0) return "Low Risk";
    if ($health_score <= 40.0) return "Mild Risk";
    if ($health_score <= 60.0) return "Moderate Risk";
    if ($health_score <= 80.0) return "High Risk";
    return "Critical Risk";
}

function backfill_cluster_label($aqi, $pm10) {
    if ($aqi <= 50 && $pm10 <= 54) return "Clean";
    if ($aqi <= 100 && $pm10 <= 154) return "Moderate";
    return "Polluted";
}

// Find telemetry rows needing predictions (ordered chronologically)
echo "Querying telemetry_raw records without matching ai_predictions...\n";
$query = "
    SELECT t.id, t.temp, t.hum, t.mq135, t.pm10, t.aqi, t.`timestamp`
    FROM telemetry_raw t
    LEFT JOIN ai_predictions p ON p.`timestamp` = t.`timestamp`
    WHERE p.id IS NULL
    ORDER BY t.`timestamp` ASC, t.id ASC
";

$res = $conn->query($query);
if (!$res) {
    die("Query error: " . $conn->error . "\n");
}

$total_missing = $res->num_rows;
echo "Found {$total_missing} records needing AI prediction backfill.\n";

if ($total_missing === 0) {
    echo "All telemetry records already have corresponding AI predictions.\n";
    exit(0);
}

$batch_size = 500;
$batch_rows = [];
$inserted_count = 0;

$prev_aqi = null;
$prev_ts = null;

$insert_sql = "
    INSERT INTO ai_predictions (
        predicted_aqi, actual_aqi, category, advice, trend,
        forecast_1h, forecast_2h, forecast_3h,
        health_score, risk_level, is_anomaly, anomaly_severity,
        cluster_label, `timestamp`
    ) VALUES 
";

while ($row = $res->fetch_assoc()) {
    $raw_ts = strtotime($row['timestamp']);
    $aqi = (int)$row['aqi'];
    $pm10 = (float)$row['pm10'];
    $mq135 = (int)$row['mq135'];
    $temp = (float)$row['temp'];
    $hum = (float)$row['hum'];

    // Dynamic slope & trend estimation
    $slope = 0.0;
    if ($prev_ts !== null && $prev_aqi !== null) {
        $dt = max(1, $raw_ts - $prev_ts);
        if ($dt <= 7200) { // only consider consecutive readings within 2 hours
            $slope = (($aqi - $prev_aqi) / $dt) * 3600;
            // Clamp reasonable slope
            if (abs($slope) > 40) $slope = ($slope > 0 ? 40 : -40);
        }
    }
    $prev_ts = $raw_ts;
    $prev_aqi = $aqi;

    if ($slope > 1.0) {
        $trend = "rising";
    } elseif ($slope < -1.0) {
        $trend = "falling";
    } else {
        $trend = "stable";
    }

    $forecast_1h = (int)round(min(500, max(0, $aqi + $slope * 1)));
    $forecast_2h = (int)round(min(500, max(0, $aqi + $slope * 2)));
    $forecast_3h = (int)round(min(500, max(0, $aqi + $slope * 3)));
    $predicted_aqi = $forecast_1h;
    $actual_aqi = $aqi;

    $category = backfill_category($aqi);
    $advice = backfill_advice($aqi);
    $health_score = backfill_health_score($pm10, $mq135, $temp, $hum);
    $risk_level = backfill_risk_level($health_score, $temp);
    $cluster_label = backfill_cluster_label($aqi, $pm10);

    // Anomaly detection rules
    $is_anomaly = 0;
    $anomaly_severity = "normal";
    if ($pm10 >= 255 || $temp >= 57 || $mq135 >= 700 || abs($slope) >= 30) {
        $is_anomaly = 1;
        $anomaly_severity = "critical";
    } elseif ($pm10 >= 155 || $mq135 >= 500 || abs($slope) >= 15) {
        $is_anomaly = 1;
        $anomaly_severity = "warning";
    }

    $escaped_advice = $conn->real_escape_string($advice);
    $escaped_ts = $conn->real_escape_string($row['timestamp']);

    $batch_rows[] = "({$predicted_aqi}, {$actual_aqi}, '{$category}', '{$escaped_advice}', '{$trend}', {$forecast_1h}, {$forecast_2h}, {$forecast_3h}, {$health_score}, '{$risk_level}', {$is_anomaly}, '{$anomaly_severity}', '{$cluster_label}', '{$escaped_ts}')";

    if (count($batch_rows) >= $batch_size) {
        $sql = $insert_sql . implode(",\n", $batch_rows);
        if (!$conn->query($sql)) {
            die("Batch insert error: " . $conn->error . "\n");
        }
        $inserted_count += count($batch_rows);
        $batch_rows = [];
        echo "Inserted {$inserted_count} / {$total_missing} records...\n";
    }
}

if (count($batch_rows) > 0) {
    $sql = $insert_sql . implode(",\n", $batch_rows);
    if (!$conn->query($sql)) {
        die("Final batch insert error: " . $conn->error . "\n");
    }
    $inserted_count += count($batch_rows);
}

echo "SUCCESS: Backfilled {$inserted_count} AI predictions into ai_predictions table.\n";
$conn->close();
