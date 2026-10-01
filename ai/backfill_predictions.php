<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
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
require_once $root . "/includes/metrics.php";

echo "=== AI PREDICTIONS BACKFILL UTILITY ===\n";

// Find telemetry rows needing predictions (ordered chronologically)
echo "Querying telemetry_raw records without matching ai_predictions...\n";
$query = "
    SELECT t.id, t.device_id, t.temp, t.hum, t.mq135, t.pm10, t.aqi, t.`timestamp`
    FROM telemetry_raw t
    LEFT JOIN ai_predictions p ON p.telemetry_id = t.id
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

$previous = [];

$insert_sql = "
    INSERT INTO ai_predictions (
        predicted_aqi, actual_aqi, category, advice, trend,
        forecast_1h, forecast_2h, forecast_3h,
        health_score, risk_level, is_anomaly, anomaly_severity,
        cluster_label, telemetry_id, device_id, `timestamp`
    ) VALUES 
";

while ($row = $res->fetch_assoc()) {
    $raw_ts = strtotime($row['timestamp']);
    $device_id = (int)$row['device_id'];
    $aqi = (int)$row['aqi'];
    $pm10 = (float)$row['pm10'];
    $mq135 = (int)$row['mq135'];
    $temp = (float)$row['temp'];
    $hum = (float)$row['hum'];

    // Dynamic slope & trend estimation
    $slope = 0.0;
    $prev_ts = $previous[$device_id]['ts'] ?? null;
    $prev_aqi = $previous[$device_id]['aqi'] ?? null;
    if ($prev_ts !== null && $prev_aqi !== null) {
        $dt = max(1, $raw_ts - $prev_ts);
        if ($dt <= 7200) { // only consider consecutive readings within 2 hours
            $slope = (($aqi - $prev_aqi) / $dt) * 3600;
            // Clamp reasonable slope
            if (abs($slope) > 40) $slope = ($slope > 0 ? 40 : -40);
        }
    }
    $previous[$device_id] = ['ts' => $raw_ts, 'aqi' => $aqi];

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

    $category = get_aqi_category($aqi);
    $advice = get_aqi_advice($aqi);
    $health_score = calc_health_risk_score($pm10, $mq135, $temp, $hum);
    $risk_level = get_health_risk_level($health_score, $temp);
    $cluster_label = get_cluster_label($aqi, $pm10);

    // Ingestion Outlier & Spike Assessment
    $anomaly_data = calc_rule_anomaly($pm10, $temp, $mq135, $slope);
    $is_anomaly = $anomaly_data['is_anomaly'];
    $anomaly_severity = $anomaly_data['severity'];

    $escaped_advice = $conn->real_escape_string($advice);
    $escaped_ts = $conn->real_escape_string($row['timestamp']);

    $telemetry_id = (int)$row['id'];
    $batch_rows[] = "({$predicted_aqi}, {$actual_aqi}, '{$category}', '{$escaped_advice}', '{$trend}', {$forecast_1h}, {$forecast_2h}, {$forecast_3h}, {$health_score}, '{$risk_level}', {$is_anomaly}, '{$anomaly_severity}', '{$cluster_label}', {$telemetry_id}, {$device_id}, '{$escaped_ts}')";

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
