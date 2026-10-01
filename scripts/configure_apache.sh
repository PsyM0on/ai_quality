#!/bin/bash
set -Eeuo pipefail
cat <<'CONF' | sudo tee /etc/apache2/conf-available/ai-quality-security.conf >/dev/null
<Directory /var/www/html/ai_quality>
    Options -Indexes
    AllowOverride All
    Require all granted
</Directory>
CONF
sudo a2enmod rewrite headers >/dev/null
sudo a2enconf ai-quality-security >/dev/null
sudo apache2ctl configtest
sudo systemctl reload apache2
