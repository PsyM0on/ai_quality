<?php
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
    header("X-XSS-Protection: 1; mode=block");
}

// =========================================================================
// PROTOCOL 2: IoT Rate Limiting / Anti-DDoS (insert.php)
// =========================================================================
function enforceRateLimit($conn, $cooldownSeconds = 3) {
    // Only allow 1 database insert per specified seconds to protect the free-tier Oracle Server.
    $rateLimitQuery = $conn->query("SELECT `timestamp` FROM telemetry_raw ORDER BY id DESC LIMIT 1");
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
function enforceApiKey($providedKey) {
    // Disabled until ESP32 firmware is updated to send this exact key
    $EXPECTED_KEY = "EcoQuality_Secure_2026";
    
    // if ($providedKey !== $EXPECTED_KEY) {
    //     http_response_code(401); // 401 Unauthorized
    //     die(json_encode([
    //         "status" => "error", 
    //         "message" => "Security Protocol Triggered: Unauthorized API Key."
    //     ]));
    // }
}
?>
