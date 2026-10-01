<?php
/**
 * ECO QUALITY - AI EXECUTION MIDDLEWARE & TTL CACHE LAYER
 * 
 * Centralized function to execute Python machine learning scripts
 * with automatic file-based caching to prevent CPU/memory exhaustion
 * on low-resource Cloud VMs (Oracle Cloud Free-Tier).
 * 
 * Standard cache duration: 60 seconds (matches sensor telemetry cadence).
 */

function run_ai_script($scriptName, $deviceId = 1, $ttlSeconds = 60, $forceRefresh = false) {
    require_once __DIR__ . '/security.php';
    require __DIR__ . '/db.php';
    if (!in_array($scriptName, ['detect_anomaly.py', 'daily_summary.py', 'rf_predictor.py'], true)) {
        throw new InvalidArgumentException('Unknown analysis script');
    }
    $registered = $conn->prepare('SELECT id FROM devices WHERE id=?');
    $registered->bind_param('i', $deviceId); $registered->execute();
    $exists = $registered->get_result()->fetch_assoc();
    $conn->close();
    if (!$exists) { http_response_code(404); return json_encode(['error' => 'Unknown device']); }
    if (!attemptLimit('ai:' . ($_SERVER['REMOTE_ADDR'] ?? 'cli'), 60, 60)) {
        http_response_code(429); return json_encode(['error' => 'Request limit exceeded']);
    }
    $root = dirname(__DIR__);

    $deviceId = max(1, (int)$deviceId);

    // Storage Cache Directory
    $cacheDir = aq_storage() . '/cache';
    if (!is_dir($cacheDir)) {
        @mkdir($cacheDir, 0755, true);
    }

    $cacheKey = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $scriptName) . '_device_' . $deviceId;
    $cacheFile = $cacheDir . '/ai_' . $cacheKey . '.json';

    // 1. Check Cache Hit
    if (!$forceRefresh && file_exists($cacheFile)) {
        $fileAge = time() - filemtime($cacheFile);
        if ($fileAge < $ttlSeconds) {
            $cachedContent = file_get_contents($cacheFile);
            $testDecode = json_decode($cachedContent, true);
            // Verify cache contains valid non-error payload
            if (is_array($testDecode)) {
                return $cachedContent;
            }
        }
    }

    // 2. Resolve Python virtual environment based on OS
    $python = (PHP_OS_FAMILY === 'Windows')
        ? $root . '/.venv/Scripts/python.exe'
        : $root . '/.venv/bin/python';
        
    $script = $root . '/ai/' . $scriptName;

    // Failsafe checks
    if (!file_exists($python)) {
        error_log("Python executable not found: " . $python);
        return json_encode(["error" => "AI service unavailable"]);
    }
    if (!file_exists($script)) {
        error_log("AI script not found: " . $script);
        return json_encode(["error" => "AI service unavailable"]);
    }

    // Serialize computation per device to prevent cache stampedes/model write races.
    $lock = fopen($cacheFile . '.lock', 'c');
    if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
        if ($lock) fclose($lock);
        http_response_code(503);
        return json_encode(["error" => "AI service busy"]);
    }
    clearstatcache(true, $cacheFile);
    if (!$forceRefresh && file_exists($cacheFile) && time() - filemtime($cacheFile) < $ttlSeconds) {
        $cached = file_get_contents($cacheFile);
        flock($lock, LOCK_UN); fclose($lock);
        return $cached;
    }
    // File descriptors avoid pipe deadlocks on Windows. No shell is involved.
    $stdout = tmpfile(); $stderr = tmpfile();
    $process = proc_open([$python, $script, '--device-id', (string)$deviceId],
        [0 => ['pipe', 'r'], 1 => $stdout, 2 => $stderr], $pipes, $root, null, ['bypass_shell' => true]);
    if (!is_resource($process)) {
        fclose($stdout); fclose($stderr); flock($lock, LOCK_UN); fclose($lock);
        return json_encode(['error' => 'AI service unavailable']);
    }
    fclose($pipes[0]);
    $deadline = microtime(true) + 45;
    do {
        $status = proc_get_status($process);
        if (!$status['running']) break;
        usleep(100000);
    } while (microtime(true) < $deadline);
    if ($status['running']) proc_terminate($process);
    proc_close($process);
    rewind($stdout); $output = stream_get_contents($stdout, 1048576); fclose($stdout);
    rewind($stderr); $diagnostic = stream_get_contents($stderr, 4096); fclose($stderr);
    if ($status['running']) $output = json_encode(['error' => 'Analysis timed out']);
    if ($diagnostic) error_log('AI diagnostic: ' . $diagnostic);

    // Extract exactly the JSON block from output
    $decoded = json_decode(trim($output), true);
    $matches = is_array($decoded) ? [trim($output)] : [];

    if (isset($matches[0])) {
        $jsonStr = $matches[0];
        $parsed = json_decode($jsonStr, true);

        // Only cache successful, non-error ML inferences
        if ($parsed !== null) {
            @file_put_contents($cacheFile, $jsonStr, LOCK_EX);
        }
        flock($lock, LOCK_UN); fclose($lock);
        return $jsonStr;
    } else {
        error_log('Invalid AI output for ' . $scriptName . ': ' . substr((string)$output, 0, 1000));
        flock($lock, LOCK_UN); fclose($lock);
        return json_encode(["error" => "AI service returned an invalid response"]);
    }
}
?>
