<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../includes/ai_runner.php';
echo run_ai_script('daily_summary.py');
?>
