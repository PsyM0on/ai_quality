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