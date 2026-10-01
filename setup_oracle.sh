#!/bin/bash
# ================================================================
# Automated Setup Script for AI Quality Monitoring on Ubuntu VPS
# Optimized for Oracle Cloud Always Free (AMD VM.Standard.E2.1.Micro)
# ================================================================

set -e
set -o pipefail

# This bootstrap is for an empty installation. Never import a dump over live data.
if command -v mysql >/dev/null && sudo mysql -N -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='air_quality'" 2>/dev/null | grep -qv '^0$'; then
    echo 'Existing database detected. Use a backup and the incremental migration; bootstrap stopped.' >&2
    exit 1
fi

: "${AQ_DB_PASSWORD:?Set AQ_DB_PASSWORD before running this script}"
: "${AQ_DEVICE_KEYS:?Set AQ_DEVICE_KEYS to device_id:sha256(token)}"
: "${AQ_ADMIN_PASSWORD_HASH:?Set AQ_ADMIN_PASSWORD_HASH before running this script}"
[[ "$AQ_DB_PASSWORD" =~ ^[A-Za-z0-9_-]{32,}$ ]] || { echo 'Use a 32+ character base64url database password.' >&2; exit 1; }

echo "=== [1/7] Setting up 2GB Swap Space (Prevents low-memory crashes) ==="
if [ ! -f /swapfile ]; then
    sudo fallocate -l 2G /swapfile 2>/dev/null || sudo dd if=/dev/zero of=/swapfile bs=1M count=2048
    sudo chmod 600 /swapfile
    sudo mkswap /swapfile
    sudo swapon /swapfile
    echo '/swapfile none swap sw 0 0' | sudo tee -a /etc/fstab
    echo "Swap created successfully."
fi

echo "=== [2/7] Updating system packages ==="
sudo apt-get update -y

echo "=== [3/7] Installing Apache, PHP, MySQL, and Python ==="
sudo DEBIAN_FRONTEND=noninteractive apt-get install -y \
    apache2 \
    php \
    php-mysqli \
    php-json \
    php-curl \
    libapache2-mod-php \
    mysql-server \
    python3 \
    python3-pip \
    python3-venv \
    iptables-persistent \
    rsync

echo "=== [4/7] Configuring MySQL Database ==="
sudo mysql -e "CREATE DATABASE IF NOT EXISTS air_quality CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
sudo mysql -e "CREATE USER IF NOT EXISTS 'aq_user'@'localhost' IDENTIFIED BY '${AQ_DB_PASSWORD}';"
sudo mysql -e "ALTER USER 'aq_user'@'localhost' IDENTIFIED BY '${AQ_DB_PASSWORD}';"
sudo mysql -e "GRANT SELECT, INSERT, UPDATE, DELETE ON air_quality.* TO 'aq_user'@'localhost';"
sudo mysql -e "FLUSH PRIVILEGES;"

if [ -f "air_quality.sql" ]; then
    echo "Importing database schema..."
    sudo mysql air_quality < air_quality.sql
fi

echo "=== [5/7] Copying project files to Apache root ==="
sudo mkdir -p /var/www/html/ai_quality
sudo rsync -a --exclude=.git --exclude=.venv --exclude=.env --exclude=storage --exclude=ai/models --exclude=tests --exclude=scripts --exclude=migrations ./ /var/www/html/ai_quality/

echo "=== [6/7] Setting up Python Virtual Environment and ML Libraries ==="
cd /var/www/html/ai_quality
sudo python3 -c 'import sys; assert sys.version_info >= (3,8), "Python 3.8+ is required"'
if [ ! -x .venv/bin/python ]; then sudo python3 -m venv .venv; fi
sudo /var/www/html/ai_quality/.venv/bin/pip install --upgrade pip
sudo /var/www/html/ai_quality/.venv/bin/pip install -r requirements.txt
sudo chown -R root:www-data /var/www/html/ai_quality
sudo find /var/www/html/ai_quality -type d -exec chmod 750 {} +
sudo find /var/www/html/ai_quality -type f -exec chmod 640 {} +
sudo find /var/www/html/ai_quality/.venv/bin -type f -exec chmod 750 {} +
sudo mkdir -p /var/www/html/ai_quality/storage/cache /var/www/html/ai_quality/ai/models
sudo chown -R www-data:www-data /var/www/html/ai_quality/storage /var/www/html/ai_quality/ai/models
sudo chmod -R 770 /var/www/html/ai_quality/storage /var/www/html/ai_quality/ai/models

# Apache imports these values at startup; no secrets are stored below the web root.
sudo install -d -o root -g www-data -m 750 /etc/ai_quality
sudo --preserve-env=AQ_DB_PASSWORD,AQ_DEVICE_KEYS,AQ_ADMIN_PASSWORD_HASH python3 -c 'import json,os; p="/etc/ai_quality/config.json"; fd=os.open(p, os.O_WRONLY|os.O_CREAT|os.O_EXCL, 0o640); f=os.fdopen(fd,"w"); json.dump({k:os.environ[k] for k in ("AQ_DB_PASSWORD","AQ_DEVICE_KEYS","AQ_ADMIN_PASSWORD_HASH")}, f); f.close()'
sudo chown root:www-data /etc/ai_quality/config.json
sudo a2enmod rewrite headers

echo "=== [7/7] Opening Ports (HTTP 80 & HTTPS 443) ==="
sudo iptables -I INPUT 6 -m state --state NEW -p tcp --dport 80 -j ACCEPT 2>/dev/null || sudo iptables -I INPUT -p tcp --dport 80 -j ACCEPT
sudo iptables -I INPUT 6 -m state --state NEW -p tcp --dport 443 -j ACCEPT 2>/dev/null || sudo iptables -I INPUT -p tcp --dport 443 -j ACCEPT
sudo netfilter-persistent save 2>/dev/null || true

sudo systemctl restart apache2
sudo systemctl restart mysql

PUBLIC_IP=$(curl -s -4 ifconfig.me || hostname -I | awk '{print $1}')

echo ""
echo "=================================================================="
echo " SUCCESS! AI Quality System is live and running 24/7."
echo " Dashboard URL: http://${PUBLIC_IP}/ai_quality/dashboard.php"
echo " Ingestion URL: http://${PUBLIC_IP}/ai_quality/insert.php (configure TLS before connecting devices)"
echo "=================================================================="
