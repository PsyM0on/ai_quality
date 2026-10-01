<?php
require_once("includes/security.php");
require_once("includes/db.php");
require_once("includes/metrics.php");

$device_id = requestDeviceId();
enforceApiKey($device_id);

function fail_input($message) {
    http_response_code(422);
    header('Content-Type: application/json');
    die(json_encode(['status' => 'error', 'message' => $message]));
}

$dev = $conn->prepare('SELECT id FROM devices WHERE id = ?');
$dev->bind_param('i', $device_id);
$dev->execute();
if (!$dev->get_result()->fetch_assoc()) fail_input('Unknown device_id');
$dev->close();

// Acknowledgements are authenticated but do not consume the telemetry rate limit.
if (!attemptLimit('ingestion:' . $device_id, 60, 60)) {
    http_response_code(429);
    exit(json_encode(['error' => 'Request limit exceeded']));
}

if (!isset($_GET['msg'])) {
foreach (['temp', 'hum', 'mq135'] as $required) {
    if (!isset($_GET[$required]) || !is_numeric($_GET[$required])) fail_input("Missing or invalid $required");
}
$temp  = (float)$_GET['temp'];
$hum   = (float)$_GET['hum'];
$mq135 = (int)$_GET['mq135'];

// In this project, the sensor sends pm25 but it is physically PM10 data
// We map the HTTP param 'pm25' or 'pm10' to the database 'pm10' column
$pmInput = $_GET['pm10'] ?? ($_GET['pm25'] ?? null);
if ($pmInput === null || !is_numeric($pmInput)) fail_input('Missing or invalid pm10');
$pm10 = (float)$pmInput;
if ($temp < -20 || $temp > 80) fail_input('temp outside accepted range');
if ($hum < 0 || $hum > 100) fail_input('hum outside accepted range');
if ($mq135 < 0 || $mq135 > 4095) fail_input('mq135 outside accepted range');
if ($pm10 < 0 || $pm10 > 600) fail_input('pm10 outside accepted range');
$pm10 = round($pm10, 1);
}

// --- ACKNOWLEDGMENT LOGGING (C2 FEEDBACK) ---
if (isset($_GET['msg'])) {
    if (!is_string($_GET['msg']) || strlen($_GET['msg']) > 500) fail_input('Invalid acknowledgement');
    $command_id = filter_var($_GET['command_id'] ?? null, FILTER_VALIDATE_INT);
    if (!$command_id || $command_id < 1) fail_input('A positive command_id is required');
    $ack_text = preg_replace('/[\r\n\x00-\x1F\x7F]+/', ' ', $_GET['msg']);
    $ack = $conn->prepare("UPDATE device_commands SET status='acknowledged', acknowledged_at=NOW(), acknowledgement=? WHERE id=? AND device_id=? AND status='delivered'");
    $ack->bind_param('sii', $ack_text, $command_id, $device_id);
    $ack->execute();
    if ($ack->affected_rows !== 1) {
        // A lost HTTP response must not leave the device retrying a successful ACK forever.
        $previousAck = $conn->prepare('SELECT status FROM device_commands WHERE id=? AND device_id=?');
        $previousAck->bind_param('ii', $command_id, $device_id); $previousAck->execute();
        $previous = $previousAck->get_result()->fetch_assoc();
        if (!$previous || $previous['status'] !== 'acknowledged') fail_input('Command is not awaiting acknowledgement for this device');
        header('Content-Type: application/json');
        exit(json_encode(['status' => 'ack_received']));
    }
    $msg_text = preg_replace('/[\r\n\x00-\x1F\x7F]+/', ' ', (string)$_GET['msg']);
    $msg_text = htmlspecialchars(substr($msg_text, 0, 500), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $log_file = aq_storage() . "/cloud_serial.log";
    $timestamp = date("Y-m-d H:i:s");
    $log_entry = "[$timestamp] [DEV $device_id] ACK: {$msg_text}\n";
    
    // Save to dedicated result file for the Admin UI
    file_put_contents(aq_storage() . "/command_result_" . $device_id . ".txt", "[$timestamp] " . $msg_text);
    
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

$conn->begin_transaction();
try {
$deviceLock = $conn->prepare('SELECT id FROM devices WHERE id=? FOR UPDATE');
$deviceLock->bind_param('i', $device_id); $deviceLock->execute(); $deviceLock->get_result()->fetch_assoc();
enforceRateLimit($conn, $device_id, 3);
$stmt = $conn->prepare("INSERT INTO telemetry_raw (device_id, temp, hum, pm10, mq135, aqi) VALUES (?, ?, ?, ?, ?, ?)");
$stmt->bind_param("idddii", $device_id, $temp, $hum, $pm10, $mq135, $aqi);
if (!$stmt->execute()) throw new RuntimeException('Telemetry insert failed');
$inserted_telemetry_id = $conn->insert_id;

// --- AUTO-POPULATE AI PREDICTIONS ---
// Establish slope and trend from the immediate prior reading
$prev_stmt = $conn->prepare("SELECT aqi, `timestamp` FROM telemetry_raw WHERE device_id = ? AND id < ? ORDER BY id DESC LIMIT 1");
$prev_stmt->bind_param("ii", $device_id, $inserted_telemetry_id);
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
        cluster_label, telemetry_id, device_id
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
");
$pred_ins->bind_param(
    "iisssiiidssssii",
    $pred_aqi, $aqi, $category, $advice, $trend,
    $f1, $f2, $f3,
    $health_score, $risk_level, $is_anomaly, $anomaly_severity,
    $cluster_label, $inserted_telemetry_id, $device_id
);
if (!$pred_ins->execute()) throw new RuntimeException('Prediction insert failed');
$pred_ins->close();
$conn->commit();
} catch (Throwable $e) {
    $conn->rollback();
    error_log('Ingestion failed: ' . $e->getMessage());
    http_response_code(500);
    header('Content-Type: application/json');
    die(json_encode(['status' => 'error', 'message' => 'Ingestion failed']));
}
// ------------------------------------

$command = "NONE";
$command_id = null;
$conn->begin_transaction();
try {
    $deviceLock = $conn->prepare('SELECT id FROM devices WHERE id=? FOR UPDATE');
    $deviceLock->bind_param('i', $device_id); $deviceLock->execute(); $deviceLock->get_result()->fetch_assoc();
    // Keep one command in flight: don't deliver newer commands while an older ACK is missing.
    $cmd = $conn->prepare("SELECT id, command, status, (delivered_at < NOW() - INTERVAL 60 SECOND) AS retry_due FROM device_commands WHERE device_id=? AND status IN ('pending','delivered') ORDER BY id ASC LIMIT 1 FOR UPDATE");
    $cmd->bind_param('i', $device_id); $cmd->execute();
    $cmd_row = $cmd->get_result()->fetch_assoc();
    if ($cmd_row && ($cmd_row['status'] === 'pending' || $cmd_row['retry_due'])) {
        $command = $cmd_row['command'];
        $command_id = (int)$cmd_row['id'];
        $delivered = $conn->prepare("UPDATE device_commands SET status='delivered', delivered_at=NOW() WHERE id=?");
        $delivered->bind_param('i', $cmd_row['id']); $delivered->execute();
    }
    $conn->commit();
} catch (Throwable $e) {
    $conn->rollback();
    error_log('Command delivery failed: ' . $e->getMessage());
    $command = 'NONE'; $command_id = null;
}

// --- CLOUD SERIAL LOGGING ---
$log_file = aq_storage() . "/cloud_serial.log";
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
    "command" => $command,
    "command_id" => $command_id
]);
$stmt->close();
?>
