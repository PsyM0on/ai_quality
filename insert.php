<?php
require_once("includes/security.php");
require_once("includes/db.php");
require_once("includes/metrics.php");

// Execute Security Protocols
enforceRateLimit($conn, 3);
// enforceApiKey($_GET['api_key'] ?? '');

$temp  = floatval($_GET['temp'] ?? 0);
$hum   = floatval($_GET['hum'] ?? 0);
$mq135 = intval($_GET['mq135'] ?? 0);

// In this project, the sensor sends pm25 but it is physically PM10 data
// We map the HTTP param 'pm25' or 'pm10' to the database 'pm10' column
$pm10  = isset($_GET['pm10']) ? floatval($_GET['pm10']) : floatval($_GET['pm25'] ?? 0);

if ($pm10 < 0) $pm10 = 0;
if ($pm10 > 600) $pm10 = 600; 
$pm10 = round($pm10, 1);

$device_id = isset($_GET['device_id']) ? intval($_GET['device_id']) : 1;

// --- ACKNOWLEDGMENT LOGGING (C2 FEEDBACK) ---
if (isset($_GET['msg'])) {
    $msg_text = htmlspecialchars($_GET['msg']);
    $log_file = __DIR__ . "/storage/cloud_serial.log";
    $timestamp = date("Y-m-d H:i:s");
    $log_entry = "[$timestamp] [DEV $device_id] ACK: {$msg_text}\n";
    
    // Save to dedicated result file for the Admin UI
    file_put_contents(__DIR__ . "/storage/command_result_" . $device_id . ".txt", "[$timestamp] " . $msg_text);
    
    $logs = file_exists($log_file) ? file($log_file) : [];
    $logs[] = $log_entry;
    if (count($logs) > 50) $logs = array_slice($logs, -50);
    file_put_contents($log_file, implode("", $logs));
    
    header('Content-Type: application/json');
    echo json_encode(["status" => "ack_received"]);
    exit;
}
// --------------------------------------------

$aqi = calc_pm10_aqi($pm10);

$stmt = $conn->prepare("INSERT INTO telemetry_raw (device_id, temp, hum, pm10, mq135, aqi) VALUES (?, ?, ?, ?, ?, ?)");
$stmt->bind_param("idddii", $device_id, $temp, $hum, $pm10, $mq135, $aqi);
$stmt->execute();
$inserted_telemetry_id = $conn->insert_id;

// --- AUTO-POPULATE AI PREDICTIONS ---
// Establish slope and trend from the immediate prior reading
$prev_stmt = $conn->prepare("SELECT aqi, `timestamp` FROM telemetry_raw WHERE id < ? ORDER BY id DESC LIMIT 1");
$prev_stmt->bind_param("i", $inserted_telemetry_id);
$prev_stmt->execute();
$prev_row = $prev_stmt->get_result()->fetch_assoc();
$prev_stmt->close();

$slope = 0.0;
if ($prev_row) {
    $prev_ts = strtotime($prev_row['timestamp']);
    $now_ts = time();
    $dt = max(1, $now_ts - $prev_ts);
    if ($dt <= 7200) {
        $slope = (($aqi - (int)$prev_row['aqi']) / $dt) * 3600;
        if (abs($slope) > 40) $slope = ($slope > 0 ? 40 : -40);
    }
}

if ($slope > 1.0) $trend = "rising";
elseif ($slope < -1.0) $trend = "falling";
else $trend = "stable";

$f1 = (int)round(min(500, max(0, $aqi + $slope * 1)));
$f2 = (int)round(min(500, max(0, $aqi + $slope * 2)));
$f3 = (int)round(min(500, max(0, $aqi + $slope * 3)));
$pred_aqi = $f1;

// Category & Advisory (Philippine Clean Air Act RA 8749 / DENR EMB)
$category = get_aqi_category($aqi);
$advice = get_aqi_advice($aqi);

// Health Risk Score & Tier Assessment
$health_score = calc_health_risk_score($pm10, $mq135, $temp, $hum);
$risk_level = get_health_risk_level($health_score, $temp);

// K-Means Cluster Pattern
$cluster_label = get_cluster_label($aqi, $pm10);

// Ingestion Outlier & Spike Assessment
$anomaly_data = calc_rule_anomaly($pm10, $temp, $mq135, $slope);
$is_anomaly = $anomaly_data['is_anomaly'];
$anomaly_severity = $anomaly_data['severity'];

$pred_ins = $conn->prepare("
    INSERT INTO ai_predictions (
        predicted_aqi, actual_aqi, category, advice, trend,
        forecast_1h, forecast_2h, forecast_3h,
        health_score, risk_level, is_anomaly, anomaly_severity,
        cluster_label
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
");
$pred_ins->bind_param(
    "iisssiiidssss",
    $pred_aqi, $aqi, $category, $advice, $trend,
    $f1, $f2, $f3,
    $health_score, $risk_level, $is_anomaly, $anomaly_severity,
    $cluster_label
);
$pred_ins->execute();
$pred_ins->close();
// ------------------------------------

$command = "NONE";
$cmd_file = __DIR__ . "/storage/command_" . $device_id . ".txt";
if (file_exists($cmd_file)) {
    $command = trim(file_get_contents($cmd_file));
    if ($command !== "NONE") {
        file_put_contents($cmd_file, "NONE"); // Reset it after sending it
    }
}

// --- CLOUD SERIAL LOGGING ---
$log_file = __DIR__ . "/storage/cloud_serial.log";
$timestamp = date("Y-m-d H:i:s");
$log_entry = "[$timestamp] [DEV $device_id] RECV: Temp={$temp}°C, Hum={$hum}%, PM10={$pm10}ug/m3, MQ135={$mq135} | CMD Sent: {$command}\n";
// Keep log file from getting too big (keep last 50 lines)
$logs = file_exists($log_file) ? file($log_file) : [];
$logs[] = $log_entry;
if (count($logs) > 50) $logs = array_slice($logs, -50);
file_put_contents($log_file, implode("", $logs));
// ----------------------------

header('Content-Type: application/json');
echo json_encode([
    "status"  => "ok",
    "pm10"    => $pm10,
    "aqi"     => $aqi,
    "command" => $command
]);
$stmt->close();
?>