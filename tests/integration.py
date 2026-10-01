"""Exercise a migrated copy of the local database, never the original database."""
import hashlib
import http.cookiejar
import json
import os
from pathlib import Path
import re
import secrets
import socket
import subprocess
import tempfile
import time
from urllib.error import HTTPError
from urllib.parse import urlencode
from urllib.request import Request, build_opener, HTTPCookieProcessor

import mysql.connector

ROOT = Path(__file__).resolve().parents[1]
PHP = r'C:\xampp\php\php.exe'
MYSQL = r'C:\xampp\mysql\bin\mysql.exe'
DUMP = r'C:\xampp\mysql\bin\mysqldump.exe'


def main():
    tag = secrets.token_hex(4)
    database = 'aq_test_' + tag
    user = database
    password = secrets.token_hex(24)
    token = secrets.token_hex(24)
    admin_password = secrets.token_hex(24)
    root = mysql.connector.connect(user='root', host='localhost')
    cursor = root.cursor()
    cursor.execute(f'CREATE DATABASE `{database}`')
    cursor.execute('CREATE USER %s@localhost IDENTIFIED BY %s', (user, password))
    cursor.execute(f'GRANT SELECT,INSERT,UPDATE,DELETE ON `{database}`.* TO %s@localhost', (user,))
    process = None
    try:
        with tempfile.TemporaryDirectory(prefix='aq-test-') as directory:
            directory = Path(directory)
            dump = directory / 'clone.sql'
            subprocess.run([DUMP, '--user=root', '--single-transaction', '--result-file=' + str(dump), 'air_quality'], check=True)
            with dump.open('rb') as source:
                subprocess.run([MYSQL, '--user=root', database], stdin=source, check=True)
            cursor.execute(f'USE `{database}`')
            cursor.execute("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=%s AND table_name='ai_predictions' AND column_name='telemetry_id'", (database,))
            if cursor.fetchone()[0]:
                cursor.execute('ALTER TABLE ai_predictions DROP FOREIGN KEY fk_prediction_telemetry, DROP FOREIGN KEY fk_prediction_device, DROP INDEX uq_prediction_telemetry, DROP INDEX idx_prediction_device, DROP COLUMN telemetry_id, DROP COLUMN device_id')
                cursor.execute('DROP TABLE device_commands')
            cursor.execute('SELECT COUNT(*) FROM telemetry_raw'); before = cursor.fetchone()[0]
            # Deliberately duplicate prediction timestamps to test ambiguous legacy matching.
            cursor.execute("INSERT INTO ai_predictions (timestamp) VALUES ('2026-06-01 00:00:00'),('2026-06-01 00:00:00')")
            root.commit()
            migration = ROOT / 'migrations/20261001_security_and_device_integrity.sql'
            with migration.open('rb') as source:
                subprocess.run([MYSQL, '--user=root', database], stdin=source, check=True)
            cursor.execute('SELECT COUNT(*) FROM telemetry_raw'); assert cursor.fetchone()[0] == before
            cursor.execute("SELECT COUNT(*) FROM ai_predictions WHERE timestamp='2026-06-01 00:00:00' AND telemetry_id IS NOT NULL")
            assert cursor.fetchone()[0] == 0
            print('PASS migration: preserves telemetry and leaves ambiguous predictions unlinked', flush=True)

            cursor.execute("INSERT INTO devices(id,name) VALUES (900001,'Test A'),(900002,'Test B')")
            # Enough regular history for all three analysis endpoints.
            for device in (900001, 900002):
                for i in range(100):
                    cursor.execute("INSERT INTO telemetry_raw(device_id,temp,hum,pm10,mq135,aqi,timestamp) VALUES (%s,%s,%s,%s,%s,%s,NOW() - INTERVAL %s MINUTE)",
                                   (device, 25+i%7/10, 60+i%5, 20+i%15, 150+i%17, 20+i%15, (101-i)*5))
            root.commit()
            storage = directory / 'storage'; (storage / 'cache').mkdir(parents=True)
            env = os.environ.copy()
            env.update(AQ_CONFIG_FILE=str(directory / 'absent.json'), AQ_DB_HOST='localhost', AQ_DB_USER=user,
                       AQ_DB_PASSWORD=password, AQ_DB_NAME=database, AQ_STORAGE_DIR=str(storage),
                       AQ_MODEL_DIR=str(directory / 'models'),
                       AQ_DEVICE_KEYS=','.join(f'{d}:{hashlib.sha256(token.encode()).hexdigest()}' for d in (900001, 900002)))
            env['TEST_ADMIN_PASSWORD'] = admin_password
            env['AQ_ADMIN_PASSWORD_HASH'] = subprocess.check_output([PHP, '-r', "echo password_hash(getenv('TEST_ADMIN_PASSWORD'), PASSWORD_DEFAULT);"], env=env, text=True).strip()
            with socket.socket() as sock:
                sock.bind(('127.0.0.1', 0)); port = sock.getsockname()[1]
            log = (directory / 'server.log').open('w+')
            process = subprocess.Popen([PHP, '-S', f'127.0.0.1:{port}', '-t', str(ROOT)], env=env, stdout=log, stderr=log)
            base = f'http://127.0.0.1:{port}/'
            opener = build_opener(HTTPCookieProcessor(http.cookiejar.CookieJar()))

            def request(path, auth=False, data=None, client=None):
                headers = {'Authorization': 'Bearer ' + token} if auth else {}
                req = Request(base + path, data=urlencode(data).encode() if data is not None else None, headers=headers)
                try:
                    with (client or opener).open(req, timeout=55) as response:
                        return response.status, response.read().decode('utf-8-sig')
                except HTTPError as exc:
                    return exc.code, exc.read().decode()

            for _ in range(40):
                try: request('admin.php'); break
                except OSError: time.sleep(.1)
            telemetry = 'insert.php?device_id=900001&temp=25&hum=60&pm10=54.5&mq135=200'
            assert request(telemetry)[0] == 401
            assert request('insert.php?device_id=-1', True)[0] == 422
            assert request('insert.php?device_id=900001&temp=25&hum=101&pm10=5&mq135=20', True)[0] == 422
            cursor.execute("INSERT INTO device_commands(device_id,command) VALUES (900001,'REBOOT')"); command_id = cursor.lastrowid; root.commit()
            cursor.execute("INSERT INTO device_commands(device_id,command) VALUES (900001,'CALIBRATE')"); second_command_id = cursor.lastrowid; root.commit()
            status, body = request(telemetry, True); payload = json.loads(body)
            assert status == 200 and payload['aqi'] == 50 and payload['command_id'] == command_id, (status, body)
            assert request(telemetry, True)[0] == 429
            cursor.execute('UPDATE telemetry_raw SET timestamp=NOW() - INTERVAL 10 SECOND WHERE device_id=900001'); root.commit()
            status, body = request(telemetry, True)
            assert status == 200 and json.loads(body)['command'] == 'NONE', body
            assert request(f'insert.php?device_id=900001&command_id={second_command_id}&msg=done', True)[0] == 422
            cursor.execute('UPDATE device_commands SET delivered_at=NOW() - INTERVAL 61 SECOND WHERE id=%s', (command_id,))
            cursor.execute('UPDATE telemetry_raw SET timestamp=NOW() - INTERVAL 10 SECOND WHERE device_id=900001'); root.commit()
            status, body = request(telemetry, True)
            assert status == 200 and json.loads(body)['command_id'] == command_id, body
            assert request(f'insert.php?device_id=900002&command_id={command_id}&msg=done', True)[0] == 422
            assert request(f'insert.php?device_id=900001&command_id={command_id}&msg=done', True)[0] == 200
            assert request(f'insert.php?device_id=900001&command_id={command_id}&msg=done', True)[0] == 200
            cursor.execute('SELECT acknowledgement FROM device_commands WHERE id=%s', (command_id,))
            assert cursor.fetchone()[0] == 'done'
            root.commit()
            cursor.execute('UPDATE telemetry_raw SET timestamp=NOW() - INTERVAL 10 SECOND WHERE device_id=900001'); root.commit()
            status, body = request(telemetry, True)
            assert status == 200 and json.loads(body)['command_id'] == second_command_id, body
            print('PASS ingestion: authentication, validation, rate limit, exact command acknowledgement', flush=True)
            status, body = request('dashboard.php?latest=1&device_id=900002'); assert json.loads(body)['device_id'] == 900002
            assert request('api/anomaly.php?device_id=999999')[0] == 404
            for endpoint in ['anomaly', 'daily', 'rf_predict']:
                status, body = request(f'api/{endpoint}.php?device_id=900001')
                result = json.loads(body)
                assert status == 200 and 'error' not in result, (endpoint, status, body)
                print('PASS AI endpoint:', endpoint, flush=True)
            status, body = request('export.php?export=1&device_id=900002&from=2026-05-06&to=2026-10-01')
            assert status == 200 and 'Timestamp' in body
            print('PASS device isolation and CSV export', flush=True)
            status, body = request('admin.php', data={'password': admin_password})
            assert 'name="logout"' in body, body[:300]
            csrf = re.search(r'name="csrf_token" value="([a-f0-9]+)"', body).group(1)
            assert request('admin.php', data={'logout': '1', 'csrf_token': 'bad'})[0] == 403
            assert request('admin.php', data={'logout': '1', 'csrf_token': csrf})[0] == 200
            for _ in range(5):
                request('admin.php', data={'password': 'wrong'}, client=build_opener())
            status, body = request('admin.php', data={'password': admin_password}, client=build_opener())
            assert status == 429 or 'CRITICAL LOCKOUT' in body
            print('PASS admin login, CSRF logout and lockout across fresh sessions', flush=True)
            # A failed prediction insert must roll back the telemetry row.
            cursor.execute("CREATE TRIGGER test_reject_prediction BEFORE INSERT ON ai_predictions FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='test rollback'")
            cursor.execute('SELECT COUNT(*) FROM telemetry_raw WHERE device_id=900002'); count = cursor.fetchone()[0]
            status, body = request(telemetry.replace('900001', '900002'), True); assert status == 500, body
            cursor.execute('SELECT COUNT(*) FROM telemetry_raw WHERE device_id=900002'); assert cursor.fetchone()[0] == count
            print('PASS failed prediction rolls back telemetry', flush=True)
            process.terminate(); process.wait(timeout=10); process = None; log.close()
    finally:
        if process: process.terminate(); process.wait(timeout=10)
        # Exact random test-only names generated above; never drop the source database.
        cursor.execute(f'DROP DATABASE `{database}`')
        cursor.execute('DROP USER %s@localhost', (user,))
        root.close()

if __name__ == '__main__': main()
