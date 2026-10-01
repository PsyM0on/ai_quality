"""Create stable production credentials outside the repository without printing them."""
import hashlib
import json
import os
from pathlib import Path
import secrets
import subprocess

PRIVATE = Path('C:/xampp/private/ai_quality')
DEVICE = PRIVATE / 'production-device-1.json'
OUTPUT = PRIVATE / 'production-config.json'
CREDENTIALS = PRIVATE / 'production-credentials.json'
PHP = 'C:/xampp/php/php.exe'

def main():
    device = json.loads(DEVICE.read_text(encoding='utf-8'))
    if hashlib.sha256(device['device_token'].encode()).hexdigest() != device['token_sha256']:
        raise SystemExit('Device token hash mismatch')
    if OUTPUT.exists() or CREDENTIALS.exists():
        print('Existing production credentials retained:', CREDENTIALS)
        return
    db_password = secrets.token_urlsafe(36)
    admin_password = secrets.token_urlsafe(24)
    environment = os.environ.copy()
    environment['AQ_PRODUCTION_ADMIN_PASSWORD'] = admin_password
    admin_hash = subprocess.check_output(
        [PHP, '-r', "echo password_hash(getenv('AQ_PRODUCTION_ADMIN_PASSWORD'), PASSWORD_DEFAULT);"],
        env=environment, text=True).strip()
    config = {
        'AQ_DB_HOST': 'localhost', 'AQ_DB_NAME': 'air_quality',
        'AQ_DB_USER': 'aq_runtime', 'AQ_DB_PASSWORD': db_password,
        'AQ_ADMIN_PASSWORD_HASH': admin_hash,
        'AQ_DEVICE_KEYS': f"{int(device['device_id'])}:{device['token_sha256']}",
    }
    OUTPUT.write_text(json.dumps(config, indent=2), encoding='utf-8')
    CREDENTIALS.write_text(json.dumps({
        'admin_password': admin_password,
        'database_user': 'aq_runtime',
        'database_password': db_password,
        'device_id': int(device['device_id']),
        'device_token': device['device_token'],
    }, indent=2), encoding='utf-8')
    identity = subprocess.check_output(['whoami'], text=True).strip()
    subprocess.run(['icacls', str(PRIVATE), '/inheritance:r', '/grant:r',
                    identity + ':(OI)(CI)F', 'SYSTEM:(OI)(CI)F'], check=True,
                   stdout=subprocess.DEVNULL)
    print('Production configuration prepared:', OUTPUT)
    print('Private credentials saved:', CREDENTIALS)

if __name__ == '__main__': main()
