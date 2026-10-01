#!/bin/bash
set -eu
echo "HOST:$(hostname)"
df -h / /var/www | tail -n +2
php -v | head -n 1
python3 --version
/var/www/html/ai_quality/.venv/bin/python --version 2>/dev/null || true
/var/www/html/ai_quality/.venv/bin/python -m pip show pandas numpy scikit-learn mysql-connector-python joblib 2>/dev/null | grep -E '^(Name|Version):' || true
sudo git -C /var/www/html/ai_quality status --short 2>/dev/null || true
sudo mysql -N -e "SELECT VERSION(); SELECT COUNT(*) FROM information_schema.columns WHERE table_schema='air_quality' AND table_name='ai_predictions' AND column_name='telemetry_id'; SELECT COUNT(*) FROM air_quality.telemetry_raw; SELECT MAX(timestamp),device_id FROM air_quality.telemetry_raw GROUP BY device_id; SELECT COUNT(*) FROM air_quality.devices;"
sudo apache2ctl -t
if sudo test -f /etc/ai_quality/config.json; then echo CONFIG_PRESENT; else echo CONFIG_ABSENT; fi
sudo stat -c '%U:%G %a %n' /var/www/html/ai_quality /var/www/html/ai_quality/storage /etc/ai_quality/config.json
