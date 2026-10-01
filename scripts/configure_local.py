"""Back up and migrate local XAMPP, then provision a private application account.

Secrets are written outside htdocs and never printed. Requires local MySQL root access.
Run only after tests/integration.py passes.
"""
import hashlib
import json
from pathlib import Path
import secrets
import subprocess
from datetime import datetime
import mysql.connector

ROOT = Path(__file__).resolve().parents[1]
PRIVATE = Path('C:/xampp/private/ai_quality')
MYSQL = 'C:/xampp/mysql/bin/mysql.exe'
PHP = 'C:/xampp/php/php.exe'

def main():
    PRIVATE.mkdir(parents=True, exist_ok=True)
    config_file = PRIVATE / 'config.json'
    if config_file.exists():
        raise SystemExit('Private configuration already exists; refusing to replace credentials.')
    backups = PRIVATE / 'backups'; backups.mkdir(exist_ok=True)
    backup = backups / ('air_quality_before_migration_' + datetime.now().strftime('%Y%m%d_%H%M%S') + '.sql')
    subprocess.run(['C:/xampp/mysql/bin/mysqldump.exe', '--user=root', '--single-transaction', '--routines', '--triggers', '--result-file=' + str(backup), 'air_quality'], check=True)
    print('Database backup:', backup)
    conn = mysql.connector.connect(user='root', host='localhost', autocommit=True)
    cur = conn.cursor()
    cur.execute("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema='air_quality' AND table_name='ai_predictions' AND column_name='telemetry_id'")
    if cur.fetchone()[0] == 0:
        with (ROOT / 'migrations/20261001_security_and_device_integrity.sql').open('rb') as source:
            subprocess.run([MYSQL, '--user=root', 'air_quality'], stdin=source, check=True)
    cur.execute('SELECT COUNT(*) FROM air_quality.device_commands'); cur.fetchone()
    password, admin_password = secrets.token_urlsafe(36), secrets.token_urlsafe(24)
    # A separate account avoids changing credentials of other local projects sharing aq_user.
    user = 'aq_runtime_' + secrets.token_hex(3)
    cur.execute('CREATE USER %s@localhost IDENTIFIED BY %s', (user, password))
    cur.execute('GRANT SELECT,INSERT,UPDATE,DELETE ON air_quality.* TO %s@localhost', (user,))
    cur.execute('SELECT id FROM air_quality.devices')
    tokens = {str(row[0]): secrets.token_urlsafe(32) for row in cur.fetchall()}
    import os
    env = os.environ.copy(); env['AQ_NEW_ADMIN_PASSWORD'] = admin_password
    password_hash = subprocess.check_output([PHP, '-r', "echo password_hash(getenv('AQ_NEW_ADMIN_PASSWORD'), PASSWORD_DEFAULT);"], env=env, text=True).strip()
    config = {'AQ_DB_HOST': 'localhost', 'AQ_DB_NAME': 'air_quality', 'AQ_DB_USER': user,
              'AQ_DB_PASSWORD': password, 'AQ_ADMIN_PASSWORD_HASH': password_hash,
              'AQ_DEVICE_KEYS': ','.join(k + ':' + hashlib.sha256(v.encode()).hexdigest() for k, v in tokens.items())}
    config_file.write_text(json.dumps(config, indent=2), encoding='utf-8')
    (PRIVATE / 'credentials.json').write_text(json.dumps({'admin_password': admin_password, 'device_tokens': tokens}, indent=2), encoding='utf-8')
    # Restrict directory inheritance to the current Windows user and SYSTEM.
    identity = subprocess.check_output(['whoami'], text=True).strip()
    subprocess.run(['icacls', str(PRIVATE), '/inheritance:r', '/grant:r', identity + ':(OI)(CI)F', 'SYSTEM:(OI)(CI)F'], check=True, stdout=subprocess.DEVNULL)
    conn.close()
    print('Local database migrated; credentials saved privately in', PRIVATE / 'credentials.json')
    print('PHP and Python automatically load', config_file)

if __name__ == '__main__': main()
