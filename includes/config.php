<?php
// Local configuration lives outside the document root; environment variables override it.
$configPath = getenv('AQ_CONFIG_FILE') ?: (PHP_OS_FAMILY === 'Windows'
    ? dirname(__DIR__, 3) . '/private/ai_quality/config.json'
    : '/etc/ai_quality/config.json');
if (is_file($configPath)) {
    $config = json_decode(file_get_contents($configPath), true, 512, JSON_THROW_ON_ERROR);
    foreach ($config as $key => $value) {
        if (preg_match('/^AQ_[A-Z_]+$/', $key) && is_scalar($value) && getenv($key) === false) {
            putenv($key . '=' . (string)$value);
        }
    }
}
date_default_timezone_set('Asia/Manila');
ini_set('display_errors', '0');
ini_set('log_errors', '1');
function aq_storage() {
    return getenv('AQ_STORAGE_DIR') ?: dirname(__DIR__) . '/storage';
}
