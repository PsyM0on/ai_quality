<?php
/**
 * export.php — Data export for reporting and analysis.
 * Exports sensor readings + AI predictions as a downloadable CSV.
 * User selects a date range; both telemetry_raw and ai_predictions are included.
 */

include("includes/db.php");

// ── SECURITY & RATE LIMITING ──────────────────────────
// Simple session-based throttle to prevent denial of service (DoS) on export
session_start();
$now = time();
$last_export = $_SESSION['last_export_time'] ?? 0;

// ── HANDLE CSV DOWNLOAD ───────────────────────────────
$do_export = isset($_GET['export']) && $_GET['export'] === '1';
$date_from = $_GET['from'] ?? date('Y-m-d', strtotime('-7 days'));
$date_to   = $_GET['to']   ?? date('Y-m-d');

// Strict date validation format (YYYY-MM-DD)
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_from)) $date_from = date('Y-m-d', strtotime('-7 days'));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_to))   $date_to   = date('Y-m-d');

// Clamp: don't allow future end dates
$today = date('Y-m-d');
if ($date_to > $today) $date_to = $today;

// Enforce max date window (e.g. max 60 days per export to prevent memory exhaustion)
$from_ts = strtotime($date_from);
$to_ts   = strtotime($date_to . ' 23:59:59');

if ($to_ts - $from_ts > (60 * 86400)) {
    // Clamp from date to max 60 days
    $from_ts = $to_ts - (60 * 86400);
    $date_from = date('Y-m-d', $from_ts);
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
            pm25,
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
        header("Location: export.php?from={$date_from}&to={$date_to}&msg=nodata");
        exit;
    }

    // Output CSV headers
    $clean_from = preg_replace('/[^0-9\-]/', '', $date_from);
    $clean_to   = preg_replace('/[^0-9\-]/', '', $date_to);
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
    fputcsv($out, ["AI-Driven Environmental Monitoring System — Data Export"]);
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
        "PM10 / PM2.5 (µg/m³)",
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

    // Stream out rows
    while ($row = $result->fetch_assoc()) {
        $raw_ts = strtotime($row['timestamp']);

        // Find closest AI prediction in memory (fast binary or linear scan within bounds)
        $closest_pred = null;
        $min_diff = 7200; // max 2 hours tolerance
        foreach ($predictions as $pred) {
            $diff = abs($pred['ts'] - $raw_ts);
            if ($diff < $min_diff) {
                $min_diff = $diff;
                $closest_pred = $pred['data'];
            }
            // Since predictions are sorted, stop early if we are past the window
            if ($pred['ts'] - $raw_ts > 7200) {
                break;
            }
        }

        fputcsv($out, [
            $row['timestamp'],
            $row['temp'],
            $row['hum'],
            $row['mq135'],
            $row['pm25'],
            $row['aqi'],
            $closest_pred['predicted_aqi'] ?? '—',
            $closest_pred['category'] ?? '—',
            $closest_pred['trend'] ?? '—',
            $closest_pred['forecast_1h'] ?? '—',
            $closest_pred['forecast_2h'] ?? '—',
            $closest_pred['forecast_3h'] ?? '—',
            $closest_pred['health_score'] ?? '—',
            $closest_pred['risk_level'] ?? '—',
            isset($closest_pred['is_anomaly']) ? ($closest_pred['is_anomaly'] ? 'Yes' : 'No') : '—',
            $closest_pred['anomaly_severity'] ?? '—',
            $closest_pred['cluster_label'] ?? '—'
        ]);
    }

    fclose($out);
    $stmt->close();
    $conn->close();
    exit;
}

// ── ROW COUNT PREVIEW ─────────────────────────────────
$count = 0;
$stmt2 = $conn->prepare("
    SELECT COUNT(*) AS cnt FROM telemetry_raw
    WHERE `timestamp` BETWEEN ? AND ?
");
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
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
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
            max-width: 480px;
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
            margin-bottom: 24px;
            line-height: 1.5;
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
    <div class="export-title">Export Telemetry</div>
    <div class="export-desc">Download a complete CSV dataset of sensor telemetry, including AI predictions and environmental anomalies.</div>

    <?php if ($msg === 'nodata'): ?>
    <div class="alert">
        ⚠️ No data found for the selected date range.
    </div>
    <?php endif; ?>

    <form method="GET" action="export.php" id="exportForm">
        <input type="hidden" name="export" value="1">

        <div class="date-row">
            <div class="input-group">
                <label>Start Date</label>
                <input type="date" name="from" id="from" value="<?= htmlspecialchars($date_from) ?>" max="<?= date('Y-m-d') ?>" required>
            </div>
            <div class="input-group">
                <label>End Date</label>
                <input type="date" name="to" id="to" value="<?= htmlspecialchars($date_to) ?>" max="<?= date('Y-m-d') ?>" required>
            </div>
        </div>

        <div class="preview-box <?= $count == 0 ? 'zero' : '' ?>" id="previewBox">
            <div class="preview-text">Rows in selection</div>
            <div class="preview-count" id="previewCount"><?= number_format($count) ?></div>
        </div>

        <button type="submit" class="btn-dl" id="dlBtn" <?= $count == 0 ? 'disabled' : '' ?>>
            Download CSV
        </button>
    </form>

    <a href="dashboard.php" class="btn-back">← Back to Dashboard</a>
</div>

<script>
    if (localStorage.getItem('aq-theme') === 'light') {
        document.body.classList.add('light');
    }

    const fromEl = document.getElementById('from');
    const toEl   = document.getElementById('to');
    const countEl = document.getElementById('previewCount');
    const boxEl   = document.getElementById('previewBox');
    const dlBtn   = document.getElementById('dlBtn');

    function updatePreview() {
        const from = fromEl.value;
        const to   = toEl.value;
        if (!from || !to || from > to) return;

        countEl.textContent = "...";

        fetch(`export_preview.php?from=${from}&to=${to}`)
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
