<?php
require_once dirname(__DIR__) . '/includes/metrics.php';

$cases = [0 => 0, 54 => 50, 55 => 51, 154 => 100, 155 => 101, 504 => 500, 600 => 500];
foreach ($cases as $pm10 => $expected) {
    $actual = calc_pm10_aqi($pm10);
    if ($actual !== $expected) {
        fwrite(STDERR, "PM10 $pm10: expected $expected, got $actual\n");
        exit(1);
    }
}
if (calc_rule_anomaly(255, 25, 100, 0)['severity'] !== 'critical') exit(1);
foreach ([54.1, 54.5, 54.9] as $value) {
    if (calc_pm10_aqi($value) !== 50) exit(1);
}
if (calc_pm10_aqi(154.9) !== 100) exit(1);
if (calc_rule_anomaly(10, 25, 100, 0)['is_anomaly'] !== 0) exit(1);
echo "metrics tests passed\n";
