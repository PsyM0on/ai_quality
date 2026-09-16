<?php
/**
 * export_preview.php — AJAX endpoint for live row count preview.
 * Called by export.php when the user changes the date range.
 */

header('Content-Type: application/json');
include("../includes/db.php");

$date_from = $_GET['from'] ?? '';
$date_to   = $_GET['to']   ?? '';

if (!$date_from || !$date_to) {
    echo json_encode(["count" => 0]);
    exit;
}

// Validate format
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_from) ||
    !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_to)) {
    echo json_encode(["count" => 0]);
    exit;
}

$from_str = $date_from . ' 00:00:00';
$to_str   = $date_to   . ' 23:59:59';

$stmt = $conn->prepare(
    "SELECT COUNT(*) AS cnt FROM telemetry_raw WHERE `timestamp` BETWEEN ? AND ?"
);
$stmt->bind_param("ss", $from_str, $to_str);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();

echo json_encode(["count" => (int)($row['cnt'] ?? 0)]);

$stmt->close();
$conn->close();
?>