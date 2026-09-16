<?php
header('Content-Type: application/json');
require_once('../includes/ai_runner.php');
echo run_ai_script('rf_predictor.py');
?>
