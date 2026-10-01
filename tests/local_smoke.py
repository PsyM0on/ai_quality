"""Read-only checks of configured XAMPP; login credentials never enter output."""
import http.cookiejar
import json
from pathlib import Path
from urllib.error import HTTPError
from urllib.parse import urlencode
from urllib.request import Request, build_opener, HTTPCookieProcessor

BASE = 'http://localhost/ai_quality/'
client = build_opener(HTTPCookieProcessor(http.cookiejar.CookieJar()))
for path, expected in [('dashboard.php', 200), ('.git/config', 403), ('air_quality.sql', 403), ('ai/utils.py', 403), ('storage/cache/', 403)]:
    try:
        with client.open(BASE + path, timeout=60) as response: status = response.status
    except HTTPError as exc: status = exc.code
    assert status == expected, (path, status)
    print('PASS', path, status)
credentials = json.loads(Path('C:/xampp/private/ai_quality/credentials.json').read_text())
req = Request(BASE + 'admin.php', data=urlencode({'password': credentials['admin_password']}).encode())
with client.open(req, timeout=15) as response:
    assert 'name="logout"' in response.read().decode()
print('PASS local admin authentication with private configuration')
for path in ['dashboard.php?latest=1', 'api/daily.php', 'api/anomaly.php', 'api/rf_predict.php']:
    with client.open(BASE + path, timeout=60) as response:
        payload = json.loads(response.read())
    assert payload is not None and 'error' not in payload, (path, payload)
    print('PASS local JSON endpoint', path)
