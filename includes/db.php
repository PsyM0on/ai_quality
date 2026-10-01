<?php
/**
 * db.php — Database connection using a dedicated limited-privilege user.
 *
 * SETUP (run once in MySQL as root):
 * Credentials are supplied through AQ_DB_* environment variables.
 * Grant only SELECT, INSERT, UPDATE, and DELETE to the runtime user.
 *   FLUSH PRIVILEGES;
 *
 * NOTE: Never use root with an empty password in production.
 *       The user above has only the permissions this app actually needs.
 */

require_once __DIR__ . '/config.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$host = getenv('AQ_DB_HOST') ?: 'localhost';
$user = getenv('AQ_DB_USER') ?: 'aq_user';
$pass = getenv('AQ_DB_PASSWORD');
$db   = getenv('AQ_DB_NAME') ?: 'air_quality';

if ($pass === false || $pass === '') {
    error_log('AQ_DB_PASSWORD is not configured');
    http_response_code(500);
    exit('Application configuration error');
}

try {
    $conn = new mysqli($host, $user, $pass, $db);
    $conn->query("SET time_zone = '+08:00'");
} catch (Throwable $e) {
    error_log('Database connection failed: ' . $e->getMessage());
    http_response_code(503);
    header('Content-Type: application/json');
    exit(json_encode(['error' => 'Database unavailable']));
}

if ($conn->connect_error) {
    http_response_code(500);
    // Don't leak the real error message to clients in production
    error_log("DB connection failed: " . $conn->connect_error);
    echo json_encode(["status" => "error", "message" => "Database connection failed"]);
    exit;
}

// Set UTF-8 charset
$conn->set_charset("utf8mb4");
?>
