<?php
require_once __DIR__ . '/config.php';

function requestDeviceId() {
    $value = $_GET['device_id'] ?? $_GET['device'] ?? '1';
    if (!is_scalar($value) || !preg_match('/^[1-9][0-9]{0,8}$/', (string)$value)) {
        http_response_code(422);
        header('Content-Type: application/json');
        exit(json_encode(['error' => 'Invalid device_id']));
    }
    return (int)$value;
}

// Atomic fixed-window limiter. All readers and writers hold the same file lock.
function attemptLimit($key, $limit, $window) {
    $path = aq_storage() . '/cache/limit_' . hash('sha256', $key) . '.json';
    $handle = fopen($path, 'c+');
    if (!$handle || !flock($handle, LOCK_EX)) throw new RuntimeException('Limiter unavailable');
    try {
        $state = json_decode(stream_get_contents($handle), true) ?: ['count' => 0, 'until' => 0];
        if ($state['until'] <= time()) $state = ['count' => 0, 'until' => time() + $window];
        $allowed = $state['count'] < $limit;
        if ($allowed) $state['count']++;
        rewind($handle); ftruncate($handle, 0); fwrite($handle, json_encode($state)); fflush($handle);
        return $allowed;
    } finally { flock($handle, LOCK_UN); fclose($handle); }
}
/**
 * ECO QUALITY - CENTRALIZED SECURITY PROTOCOLS
 * 
 * This module acts as the security middleware for the entire system.
 * It protects the application from DDoS attacks, Clickjacking, Cross-Site Scripting (XSS), 
 * and unauthorized IoT data injection.
 */

// =========================================================================
// PROTOCOL 1: Web Security Headers (dashboard.php)
// =========================================================================
function enforceWebSecurity() {
    // Enforce HTTPS
    header("Strict-Transport-Security: max-age=31536000; includeSubDomains; preload");
    // Prevent Clickjacking (stops hackers from putting the dashboard in a hidden iframe)
    header("X-Frame-Options: DENY");
    // Prevent MIME-sniffing vulnerabilities
    header("X-Content-Type-Options: nosniff");
    // Enforce Cross-Site Scripting (XSS) filter
    header("X-XSS-Protection: 0");
    header("Referrer-Policy: strict-origin-when-cross-origin");
    header("Permissions-Policy: camera=(), microphone=(), geolocation=()");
    header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline' https://unpkg.com https://cdn.jsdelivr.net; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com https://unpkg.com; font-src 'self' https://fonts.gstatic.com; img-src 'self' data: https:; connect-src 'self'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'");
}

// =========================================================================
// PROTOCOL 2: IoT Rate Limiting / Anti-DDoS (insert.php)
// =========================================================================
function enforceRateLimit($conn, $deviceId, $cooldownSeconds = 3) {
    // Only allow 1 database insert per specified seconds to protect the free-tier Oracle Server.
    $rateLimitQuery = $conn->prepare("SELECT `timestamp` FROM telemetry_raw WHERE device_id = ? ORDER BY id DESC LIMIT 1");
    $rateLimitQuery->bind_param('i', $deviceId);
    $rateLimitQuery->execute();
    $rateLimitQuery = $rateLimitQuery->get_result();
    if ($rateLimitQuery && $rateLimitQuery->num_rows > 0) {
        $lastInsertTime = strtotime($rateLimitQuery->fetch_assoc()['timestamp']);
        $currentTime = time();
        if (($currentTime - $lastInsertTime) < $cooldownSeconds) {
            http_response_code(429); // 429 Too Many Requests
            die(json_encode([
                "status" => "error", 
                "message" => "Security Protocol Triggered: Rate Limit Exceeded. Server protected from spam."
            ]));
        }
    }
}

// =========================================================================
// PROTOCOL 3: IoT API Key Authentication (insert.php)
// =========================================================================
function enforceApiKey($deviceId) {
    $configured = getenv('AQ_DEVICE_KEYS') ?: '';
    $keys = [];
    foreach (explode(',', $configured) as $entry) {
        $parts = explode(':', trim($entry), 2);
        if (count($parts) === 2) $keys[(int)$parts[0]] = trim($parts[1]);
    }
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    if ($header === '' && function_exists('getallheaders')) {
        foreach (getallheaders() as $name => $value) {
            if (strcasecmp($name, 'Authorization') === 0) {
                $header = $value;
                break;
            }
        }
    }
    $provided = preg_match('/^Bearer\s+(.+)$/i', $header, $m) ? trim($m[1]) : '';
    if (!isset($keys[$deviceId]) || $provided === '' || !hash_equals($keys[$deviceId], hash('sha256', $provided))) {
        http_response_code(401);
        header('Content-Type: application/json');
        die(json_encode(['status' => 'error', 'message' => 'Unauthorized device']));
    }
}
?>
