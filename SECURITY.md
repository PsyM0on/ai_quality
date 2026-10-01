# Production security setup

## Local XAMPP configuration

Local configuration is automatically loaded from
`C:/xampp/private/ai_quality/config.json` by both PHP and Python. Environment
variables override it. Raw admin/device credentials are in the adjacent
`credentials.json`, outside the web root with restricted Windows permissions.
Do not send or commit either file. Backups are in the adjacent `backups` directory.

`scripts/configure_local.py` backs up and migrates an existing local database and
creates a new restricted application account. It refuses to overwrite existing
private credentials. The old `aq_user` account is left alone because other local
projects may use it; remove its access to this database after checking those projects.

`tests/integration.py` restores a temporary copy, tests migration, authenticated
ingestion, exact command acknowledgements, invalid input, device isolation, exports,
the AI endpoints, admin sessions/lockout, and transaction rollback. It removes only
its randomly named test database/account afterward.

## ESP32 deployment dependency

The updated sketch is in `firmware/eco_quality_esp32/`. Before activating production authentication,
upload its privately provisioned `.ino` copy to the real ESP32. It uses HTTPS with
certificate verification and send `Authorization: Bearer <raw token>` on telemetry
and acknowledgement requests. Never use `setInsecure()`.

Read `command_id` from the telemetry JSON response. Acknowledge with
`insert.php?device_id=1&command_id=<id>&msg=done` and the same Authorization header;
no sensor values are required for acknowledgements. Persist processed command IDs
on the board so a retry does not repeat a reboot or calibration. Unacknowledged
commands can be redelivered after 60 seconds.

The existing server is `eco-quality.duckdns.org`. Coordinate its release and new
production credentials with the firmware update; deploying authentication first
will reject old firmware. Local credentials are not production credentials.

## Server configuration

The application fails closed when required secrets are absent. Copy `.env.example`
only as a reference; do not place a real `.env` below the Apache document root.

Set `AQ_DB_PASSWORD`, `AQ_DEVICE_KEYS`, and `AQ_ADMIN_PASSWORD_HASH` in
`/etc/ai_quality/config.json` (root:www-data, 0640), or Apache's protected environment.
The JSON file is also loaded by CLI Python. `AQ_DEVICE_KEYS` contains SHA-256 hashes, not raw
device tokens. Generate values with:

```sh
php -r "echo password_hash('a-long-admin-passphrase', PASSWORD_DEFAULT), PHP_EOL;"
printf '%s' 'a-long-random-device-token' | sha256sum
```

Apply `migrations/20261001_security_and_device_integrity.sql` to an existing database
before deploying the new PHP code. Back up the database first. Rotate the old database
and admin passwords because earlier revisions contained them.

Configure a DNS name and a trusted TLS certificate (for example with Certbot), redirect
HTTP to HTTPS, and only then provision raw bearer tokens to devices. The device sends:

```text
Authorization: Bearer <raw-device-token>
```

Keep `.git`, SQL dumps, deployment scripts, and documentation outside the public Apache
document root in production. Only `storage/` and `ai/models/` should be writable by the
web-server account. Disable `display_errors` and retain private server-side error logs.
