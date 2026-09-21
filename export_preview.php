<?php
include("includes/db.php");

$date_from = $_GET['from'] ?? date('Y-m-d', strtotime('-7 days'));
$date_to   = $_GET['to']   ?? date('Y-m-d');

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_from)) $date_from = date('Y-m-d', strtotime('-7 days'));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_to))   $date_to   = date('Y-m-d');

$today = date('Y-m-d');
if ($date_to > $today) $date_to = $today;

$from_ts = strtotime($date_from);
$to_ts   = strtotime($date_to . ' 23:59:59');

// Active data collection started on May 6, 2026 (May 4-5 were preliminary calibration tests)
$min_date = '2026-05-06';

if ($date_from < $min_date) {
    $date_from = $min_date;
    $from_ts = strtotime($date_from);
}

if ($to_ts - $from_ts > (365 * 86400)) {
    $from_ts = $to_ts - (365 * 86400);
    $date_from = date('Y-m-d', $from_ts);
}

$from_str = date('Y-m-d 00:00:00', $from_ts);
$to_str   = date('Y-m-d 23:59:59', $to_ts);

$stmt = $conn->prepare("SELECT COUNT(*) AS cnt FROM telemetry_raw WHERE `timestamp` BETWEEN ? AND ?");
$stmt->bind_param("ss", $from_str, $to_str);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$count = $row['cnt'] ?? 0;

$stmt->close();
$conn->close();

header('Content-Type: application/json');
echo json_encode(['count' => $count]);

