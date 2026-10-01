"""Non-mutating production checks using private, local credentials."""
import http.cookiejar
import json
from pathlib import Path
from urllib.error import HTTPError
from urllib.parse import urlencode
from urllib.request import Request, build_opener, HTTPCookieProcessor

BASE = 'https://eco-quality.duckdns.org/ai_quality/'
credentials = json.loads(Path('C:/xampp/private/ai_quality/production-credentials.json').read_text())
client = build_opener(HTTPCookieProcessor(http.cookiejar.CookieJar()))

def request(path, data=None, headers=None):
    req = Request(BASE + path, data=urlencode(data).encode() if data else None, headers=headers or {})
    try:
        with client.open(req, timeout=60) as response:
            return response.status, response.read().decode('utf-8-sig')
    except HTTPError as exc:
        return exc.code, exc.read().decode()

for path, expected in [('dashboard.php', 200), ('.git/config', 403), ('air_quality.sql', 403), ('firmware/README.md', 403)]:
    status, _ = request(path)
    assert status == expected, (path, status)
    print('PASS', path, status)

status, body = request('insert.php?device_id=1&temp=25&hum=60&pm10=20&mq135=100')
assert status == 401, ('unauthenticated ingestion', status, body)
auth = {'Authorization': 'Bearer ' + credentials['device_token']}
status, body = request('insert.php?device_id=1&temp=25&hum=101&pm10=20&mq135=100', headers=auth)
assert status == 422, ('invalid telemetry', status, body)
print('PASS production authentication and validation without inserting telemetry')

status, body = request('admin.php', data={'password': credentials['admin_password']})
assert status == 200 and 'name="logout"' in body
print('PASS production admin authentication')

for endpoint in ('dashboard.php?latest=1', 'api/daily.php', 'api/anomaly.php', 'api/rf_predict.php'):
    status, body = request(endpoint)
    payload = json.loads(body)
    assert status == 200 and 'error' not in payload, (endpoint, status, payload)
    print('PASS production JSON endpoint', endpoint)
