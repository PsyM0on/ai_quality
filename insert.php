<?php
require_once("includes/security.php");
include("includes/db.php");

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

// AQI CALCULATION (PHILIPPINE CLEAN AIR ACT RA 8749 / DENR EMB)
function calcAQI($pm) {
    // Philippine DENR EMB Breakpoints for PM10 (ug/m3, 24-hr avg, DAO 2000-81)
    $bp = [
        [0, 54, 0, 50],       // Good
        [55, 154, 51, 100],   // Fair
        [155, 254, 101, 150], // Unhealthy for Sensitive Groups
        [255, 354, 151, 200], // Very Unhealthy
        [355, 424, 201, 300], // Acutely Unhealthy
        [425, 604, 301, 500]  // Emergency
    ];

    foreach ($bp as $b) {
        list($cl, $ch, $il, $ih) = $b;
        if ($pm >= $cl && $pm <= $ch) {
            return round((($ih - $il) / ($ch - $cl)) * ($pm - $cl) + $il);
        }
    }
    return 500;
}

$device_id = isset($_GET['device_id']) ? intval($_GET['device_id']) : 1;

// --- ACKNOWLEDGMENT LOGGING (C2 FEEDBACK) ---
if (isset($_GET['msg'])) {
    $msg_text = htmlspecialchars($_GET['msg']);
    $log_file = "cloud_serial.log";
    $timestamp = date("Y-m-d H:i:s");
    $log_entry = "[$timestamp] [DEV $device_id] ACK: {$msg_text}\n";
    
    // Save to dedicated result file for the Admin UI
    file_put_contents("command_result_" . $device_id . ".txt", "[$timestamp] " . $msg_text);
    
    $logs = file_exists($log_file) ? file($log_file) : [];
    $logs[] = $log_entry;
    if (count($logs) > 50) $logs = array_slice($logs, -50);
    file_put_contents($log_file, implode("", $logs));
    
    header('Content-Type: application/json');
    echo json_encode(["status" => "ack_received"]);
    exit;
}
// --------------------------------------------

$aqi = calcAQI($pm10);

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

// Category & Advice (Philippine Clean Air Act RA 8749 / DENR EMB)
if ($aqi <= 50) {
    $category = "Good";
    $advice = "Air quality is satisfactory. No air pollution health risks (DENR Good).";
} elseif ($aqi <= 100) {
    $category = "Fair";
    $advice = "Air quality is acceptable (Fair). Unusually sensitive individuals should consider limiting prolonged outdoor exertion.";
} elseif ($aqi <= 150) {
    $category = "Unhealthy for Sensitive Groups";
    $advice = "Unhealthy for Sensitive Groups. People with respiratory or heart disease, the elderly, and children should limit outdoor exertion.";
} elseif ($aqi <= 200) {
    $category = "Very Unhealthy";
    $advice = "Very Unhealthy. People with respiratory illness should avoid outdoor exertion; everyone else should limit prolonged exposure.";
} elseif ($aqi <= 300) {
    $category = "Acutely Unhealthy";
    $advice = "Acutely Unhealthy. People with respiratory disease (asthma) must stay indoors; general public should avoid outdoor exertion.";
} else {
    $category = "Emergency";
    $advice = "EMERGENCY. Everyone should avoid outdoor exertion; remain indoors with doors and windows closed.";
}

// Health Risk Score & Risk Level (Standard Project Weighted Model)
$pm_score  = min($pm10 / 325.4, 1.0) * 100;
$voc_score = min(max($mq135 - 300, 0) / 400.0, 1.0) * 100;
$hum_score = min(max(abs($hum - 50) - 10, 0) / 40.0, 1.0) * 100;
$tmp_score = min(max($temp - 35, 0) / 15.0, 1.0) * 100;
$health_score = round(min($pm_score * 0.50 + $voc_score * 0.30 + $hum_score * 0.10 + $tmp_score * 0.10, 100.0), 1);

if ($temp > 74.0) $risk_level = "Critical Risk";
elseif ($temp >= 57.0) $risk_level = "High Risk";
elseif ($health_score <= 20.0) $risk_level = "Low Risk";
elseif ($health_score <= 40.0) $risk_level = "Mild Risk";
elseif ($health_score <= 60.0) $risk_level = "Moderate Risk";
elseif ($health_score <= 80.0) $risk_level = "High Risk";
else $risk_level = "Critical Risk";

// K-Means Cluster Label
if ($aqi <= 50 && $pm10 <= 54) $cluster_label = "Clean";
elseif ($aqi <= 100 && $pm10 <= 154) $cluster_label = "Moderate";
else $cluster_label = "Polluted";

// Statistical Anomaly & Outlier Assessment
$is_anomaly = 0;
$anomaly_severity = "normal";
if ($pm10 >= 255 || $temp >= 57 || $mq135 >= 700 || abs($slope) >= 30) {
    $is_anomaly = 1;
    $anomaly_severity = "critical";
} elseif ($pm10 >= 155 || $mq135 >= 500 || abs($slope) >= 15) {
    $is_anomaly = 1;
    $anomaly_severity = "warning";
}

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
$cmd_file = "command_" . $device_id . ".txt";
if (file_exists($cmd_file)) {
    $command = trim(file_get_contents($cmd_file));
    if ($command !== "NONE") {
        file_put_contents($cmd_file, "NONE"); // Reset it after sending it
    }
}

// --- CLOUD SERIAL LOGGING ---
$log_file = "cloud_serial.log";
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