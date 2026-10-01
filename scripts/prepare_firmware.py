"""Prepare a private Arduino upload copy without printing or committing any secrets.

Reads Wi-Fi credentials from the supplied original sketch. Creates a separate staged
production device token; this does NOT activate it or deploy code on the server.
"""
import argparse
import hashlib
import json
from pathlib import Path
import re
import secrets
import shutil

ROOT = Path(__file__).resolve().parents[1]
PRIVATE = Path('C:/xampp/private/ai_quality')

def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--original', type=Path, required=True)
    args = parser.parse_args()
    original = args.original.read_text(encoding='utf-8-sig')
    def credential(name):
        match = re.search(r'const\s+char\s*\*\s*' + name + r'\s*=\s*("(?:\\.|[^"\\])*")\s*;', original)
        if match:
            return json.loads(match.group(1))
        # Recover the common pasted-sketch typo `password = value";` without
        # printing the value. Reject whitespace/control characters or ambiguity.
        malformed = re.search(r'const\s+char\s*\*\s*' + name + r'\s*=\s*([^"\r\n]+)"\s*;', original)
        if malformed and malformed.group(1) == malformed.group(1).strip() and not re.search(r'[\x00-\x20]', malformed.group(1)):
            print(f'Warning: repaired the missing opening quote in the private {name} value.')
            return malformed.group(1)
        raise SystemExit('Original sketch is missing or has an ambiguous required Wi-Fi setting.')
    ssid, wifi_password = credential('ssid'), credential('password')
    PRIVATE.mkdir(parents=True, exist_ok=True)
    manifest_path = PRIVATE / 'production-device-1.json'
    if manifest_path.exists():
        manifest = json.loads(manifest_path.read_text(encoding='utf-8'))
    else:
        token = secrets.token_urlsafe(32)
        manifest = {'device_id': 1, 'device_token': token,
                    'token_sha256': hashlib.sha256(token.encode()).hexdigest(),
                    'endpoint': 'https://eco-quality.duckdns.org/ai_quality/insert.php',
                    'activated': False}
        with manifest_path.open('x', encoding='utf-8') as stream:
            json.dump(manifest, stream, indent=2)
    if hashlib.sha256(manifest['device_token'].encode()).hexdigest() != manifest['token_sha256']:
        raise SystemExit('Staged token manifest is inconsistent; refusing to overwrite it.')
    destination = PRIVATE / 'firmware' / 'eco_quality_esp32'
    destination.mkdir(parents=True, exist_ok=True)
    for name in ('eco_quality_esp32.ino', 'protocol.h', 'trust_anchor.h', 'secrets.example.h'):
        shutil.copy2(ROOT / 'firmware/eco_quality_esp32' / name, destination / name)
    header = '#pragma once\n// Private provisioning; never share this file or the compiled firmware.\n'
    for key, value in [('AQ_WIFI_SSID', ssid), ('AQ_WIFI_PASSWORD', wifi_password),
                       ('AQ_DEVICE_TOKEN', manifest['device_token']), ('AQ_SERVER_URL', manifest['endpoint'])]:
        header += f'static const char {key}[] = {json.dumps(value, ensure_ascii=True)};\n'
    header += f'static const uint32_t AQ_DEVICE_ID = {int(manifest["device_id"])};\n'
    (destination / 'secrets.h').write_text(header, encoding='utf-8')
    print('Private upload sketch:', destination / 'eco_quality_esp32.ino')
    print('Production token manifest:', manifest_path)
    print('Token is staged only. Live server activation and physical board upload are still required.')

if __name__ == '__main__': main()
