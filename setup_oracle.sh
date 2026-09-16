#!/bin/bash
# ================================================================
# Automated Setup Script for AI Quality Monitoring on Ubuntu VPS
# Optimized for Oracle Cloud Always Free (AMD VM.Standard.E2.1.Micro)
# ================================================================

set -e

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
    iptables-persistent

echo "=== [4/7] Configuring MySQL Database ==="
sudo mysql -e "CREATE DATABASE IF NOT EXISTS ai_quality CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
sudo mysql -e "CREATE USER IF NOT EXISTS 'aq_user'@'localhost' IDENTIFIED BY 'aq_secure_2024';"
sudo mysql -e "GRANT ALL PRIVILEGES ON ai_quality.* TO 'aq_user'@'localhost';"
sudo mysql -e "FLUSH PRIVILEGES;"

if [ -f "ai_quality.sql" ]; then
    echo "Importing database schema..."
    sudo mysql -u aq_user -paq_secure_2024 ai_quality < ai_quality.sql
fi

echo "=== [5/7] Copying project files to Apache root ==="
sudo mkdir -p /var/www/html/ai_quality
sudo cp -r . /var/www/html/ai_quality/

echo "=== [6/7] Setting up Python Virtual Environment and ML Libraries ==="
cd /var/www/html/ai_quality
sudo rm -rf .venv
sudo python3 -m venv .venv
sudo /var/www/html/ai_quality/.venv/bin/pip install --upgrade pip
sudo /var/www/html/ai_quality/.venv/bin/pip install pandas numpy scikit-learn mysql-connector-python
sudo chown -R www-data:www-data /var/www/html/ai_quality
sudo chmod -R 755 /var/www/html/ai_quality

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
echo " Ingestion URL: http://${PUBLIC_IP}/ai_quality/insert.php"
echo "=================================================================="
