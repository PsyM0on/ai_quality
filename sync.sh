#!/bin/bash
cd /var/www/html/ai_quality
export GIT_SSH_COMMAND="ssh -o StrictHostKeyChecking=no"
git fetch origin
git reset --hard origin/main
chown -R www-data:www-data /var/www/html/ai_quality/
