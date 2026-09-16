<?php
/**
 * db.php — Database connection using a dedicated limited-privilege user.
 *
 * SETUP (run once in MySQL as root):
 *   CREATE USER 'aq_user'@'localhost' IDENTIFIED BY 'aq_secure_2024';
 *   GRANT SELECT, INSERT, UPDATE ON ai_quality.* TO 'aq_user'@'localhost';
 *   FLUSH PRIVILEGES;
 *
 * NOTE: Never use root with an empty password in production.
 *       The user above has only the permissions this app actually needs.
 */

$host = "localhost";
$user = "aq_user";
$pass = "aq_secure_2024";   // ← Match the password you set in MySQL
$db   = "air_quality";

$conn = new mysqli($host, $user, $pass, $db);

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