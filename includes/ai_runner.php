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

function run_ai_script($scriptName, $ttlSeconds = 60, $forceRefresh = false) {
    $root = dirname(__DIR__);

    // Check query params for forced bypass during diagnostic testing
    if (isset($_GET['nocache']) || isset($_GET['refresh'])) {
        $forceRefresh = true;
    }

    // Storage Cache Directory
    $cacheDir = $root . '/storage/cache';
    if (!is_dir($cacheDir)) {
        @mkdir($cacheDir, 0755, true);
    }

    $cacheKey = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $scriptName);
    $cacheFile = $cacheDir . '/ai_' . $cacheKey . '.json';

    // 1. Check Cache Hit
    if (!$forceRefresh && file_exists($cacheFile)) {
        $fileAge = time() - filemtime($cacheFile);
        if ($fileAge < $ttlSeconds) {
            $cachedContent = file_get_contents($cacheFile);
            $testDecode = json_decode($cachedContent, true);
            // Verify cache contains valid non-error payload
            if ($testDecode !== null && !isset($testDecode['error'])) {
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
        return json_encode(["error" => "Python executable not found. Ensure .venv exists.", "path" => $python]);
    }
    if (!file_exists($script)) {
        return json_encode(["error" => "Script '$scriptName' not found", "path" => $script]);
    }

    // 3. Execute script and capture JSON output
    $output = shell_exec('"' . $python . '" "' . $script . '" 2>&1');

    // Extract exactly the JSON block from output
    preg_match('/\{.*\}/s', $output, $matches);

    if (isset($matches[0])) {
        $jsonStr = $matches[0];
        $parsed = json_decode($jsonStr, true);

        // Only cache successful, non-error ML inferences
        if ($parsed !== null && !isset($parsed['error'])) {
            @file_put_contents($cacheFile, $jsonStr, LOCK_EX);
        }
        return $jsonStr;
    } else {
        return json_encode(["error" => "Invalid AI script output format", "debug" => $output]);
    }
}
?>
