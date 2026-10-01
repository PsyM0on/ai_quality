<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../includes/ai_runner.php';
require_once __DIR__ . '/../includes/security.php';
$deviceId = requestDeviceId();
echo run_ai_script('detect_anomaly.py', $deviceId);
?>
