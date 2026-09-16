<?php
/**
 * ECO QUALITY - AI EXECUTION MIDDLEWARE
 * 
 * Centralized function to execute Python machine learning scripts.
 * Refactored using the D.R.Y. (Don't Repeat Yourself) principle.
 */

function run_ai_script($scriptName) {
    // Dynamically resolve Python virtual environment based on OS
    $root = dirname(__DIR__);
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

    // Execute script and capture JSON output
    $output = shell_exec('"' . $python . '" "' . $script . '" 2>&1');

    // Extract exactly the JSON block from output
    preg_match('/\{.*\}/s', $output, $matches);

    if (isset($matches[0])) {
        return $matches[0];
    } else {
        return json_encode(["error" => "Invalid AI script output format", "debug" => $output]);
    }
}
?>
