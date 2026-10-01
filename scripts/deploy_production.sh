#!/bin/bash
set -Eeuo pipefail
APP=/var/www/html/ai_quality
RELEASE=/tmp/ai-quality-release
ARCHIVE=/tmp/ai-quality-release.tar.gz
CONFIG=/tmp/ai-quality-production-config.json
BACKUPS=/var/backups/ai-quality
STAMP=$(date -u +%Y%m%dT%H%M%SZ)

test -f "$ARCHIVE"
test -f "$CONFIG"
sudo install -d -m 700 "$BACKUPS"
sudo mysqldump --single-transaction --routines --triggers air_quality | gzip | sudo tee "$BACKUPS/database-$STAMP.sql.gz" >/dev/null
sudo tar --exclude=.git --exclude=.venv --exclude=storage/cache --exclude=ai/models \
  -czf "$BACKUPS/code-$STAMP.tar.gz" -C /var/www/html ai_quality
sudo chmod 600 "$BACKUPS/database-$STAMP.sql.gz" "$BACKUPS/code-$STAMP.tar.gz"

rm -rf "$RELEASE"
mkdir -p "$RELEASE"
tar -xzf "$ARCHIVE" -C "$RELEASE"
php -l "$RELEASE/insert.php" >/dev/null
python3 -m compileall -q "$RELEASE/ai"

if ! sudo mysql -N -e "SELECT COUNT(*) FROM information_schema.columns WHERE table_schema='air_quality' AND table_name='ai_predictions' AND column_name='telemetry_id'" | grep -qx 1; then
  sudo mysql air_quality < "$RELEASE/migrations/20261001_security_and_device_integrity.sql"
fi

# Passwords are constrained to base64url by the generator. Nothing secret is printed.
sudo CONFIG_PATH="$CONFIG" python3 - <<'PY' | sudo mysql
import json, os, re
c=json.load(open(os.environ['CONFIG_PATH'], encoding='utf-8'))
u=c['AQ_DB_USER']; p=c['AQ_DB_PASSWORD']
assert re.fullmatch(r'[A-Za-z0-9_]{1,32}', u)
assert re.fullmatch(r'[A-Za-z0-9_-]{32,}', p)
print("CREATE USER IF NOT EXISTS '%s'@'localhost' IDENTIFIED BY '%s';" % (u,p))
print("ALTER USER '%s'@'localhost' IDENTIFIED BY '%s';" % (u,p))
print("GRANT SELECT,INSERT,UPDATE,DELETE ON air_quality.* TO '%s'@'localhost';" % u)
PY

sudo install -d -o root -g www-data -m 750 /etc/ai_quality
sudo install -o root -g www-data -m 640 "$CONFIG" /etc/ai_quality/config.json
sudo rsync -a --delete \
  --exclude=.git --exclude=.venv --exclude=storage --exclude=ai/models --exclude=downloads \
  "$RELEASE/" "$APP/"
sudo install -d -o www-data -g www-data -m 770 "$APP/storage" "$APP/storage/cache" "$APP/ai/models"
sudo chown -R root:www-data "$APP"
sudo chown -R www-data:www-data "$APP/storage" "$APP/ai/models"
sudo find "$APP" -path "$APP/.venv" -prune -o -path "$APP/storage" -prune -o -path "$APP/ai/models" -prune -o -type d -exec chmod 750 {} +
sudo find "$APP" -path "$APP/.venv" -prune -o -path "$APP/storage" -prune -o -path "$APP/ai/models" -prune -o -type f -exec chmod 640 {} +
sudo "$APP/.venv/bin/pip" install -q -r "$APP/requirements.txt"
sudo tee /etc/apache2/conf-available/ai-quality-security.conf >/dev/null <<'APACHE'
<Directory /var/www/html/ai_quality>
    Options -Indexes
    AllowOverride All
    Require all granted
</Directory>
APACHE
sudo a2enmod rewrite headers >/dev/null
sudo a2enconf ai-quality-security >/dev/null
sudo apache2ctl configtest
sudo systemctl restart apache2
sudo systemctl is-active --quiet apache2
curl -fsS --max-time 20 https://eco-quality.duckdns.org/ai_quality/dashboard.php >/dev/null
echo "Deployment completed. Rollback artifacts: $BACKUPS/*-$STAMP.*"
