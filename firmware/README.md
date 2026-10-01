# Eco Quality ESP32

Open `eco_quality_esp32/eco_quality_esp32.ino` in Arduino IDE. The sketch targets a
classic ESP32 (ESP32 Dev Module) with Arduino ESP32 core **3.3.12**, DHT sensor library
**1.4.7**, Adafruit Unified Sensor **1.1.15**, and ArduinoJson **7.4.2**.

Pins retained from the supplied sketch: DHT22 GPIO4, MQ135 analog GPIO34, PMS5003
UART2 RX16/TX17 at 9600 baud. Normal telemetry interval remains five seconds.

## Private upload copy

Run `scripts/prepare_firmware.py --original <path-to-original-sketch>` using the
project Python environment. It extracts the existing Wi-Fi settings, creates a
separate staged production bearer token and places the complete upload sketch at:

`C:/xampp/private/ai_quality/firmware/eco_quality_esp32/eco_quality_esp32.ino`

Open that PRIVATE copy to upload. The repository copy has empty example credentials
and deliberately makes no requests until configured. The private directory and its
compiled firmware contain secrets and must not be shared or committed.

The matching token hash is in `C:/xampp/private/ai_quality/production-device-1.json`.
It must be installed as device 1's hash in the production server configuration during
activation. This is separate from local development credentials. Preparation does
not deploy anything, change the server, or flash the board.

## Behavior and recovery

- Uses the HTTPS hostname, ISRG Root X1 trust anchor and SNTP time. TLS certificate
  or hostname failures stop transmission; HTTP redirects are not followed.
- Sends a bearer header on telemetry and acknowledgements, parses JSON, and only
  handles commands received in a successful `status: ok` response.
- Saves command intent before execution, then saves completion and retries its exact
  acknowledgement. Reboot completion is acknowledged after boot. An interrupted
  calibration/pause is reported as interrupted and is not automatically repeated.
- Persists MQ135 baseline with command state. This retains the original zero-offset
  calculation; it is not a calibrated gas-concentration measurement.
- Checks PMS frame length/checksum and atmospheric PM10 bytes. Skips stale PM readings
  and invalid DHT values instead of publishing fabricated defaults. PM10 above the
  server's current 600 range is skipped with a serial warning, not reported as clean.
- Continues watchdog servicing during Wi-Fi loss, warm-up and sensor sleep. Request
  timeouts and backoff prevent tight network retry loops. PMS warm-up is 30 seconds.

NVS retains the highest command ID for this device. If replacing the server database
with one that resets IDs, reconcile command state before clearing NVS; otherwise old
commands may be replayed. Do not change `AQ_DEVICE_ID` and blindly reuse another
device's saved state. Simultaneous flash writes and physical actions cannot be made
atomic; interruption reporting is intentional.

No boot message is sent as a fake command acknowledgement. Telemetry is the boot
heartbeat. Hardware commands still require functional readings to reach the server.

## Verification and activation

Compile the private copy for `esp32:esp32:esp32`. Connect the board, select its actual
serial port and upload. Confirm serial output at 115200 baud. The existing server can
accept the new telemetry header before server activation; legacy commands without
IDs are deliberately ignored. Activate the updated server and staged token hash only
after the board is running this firmware, then test one command at a time.

API references: [Espressif Preferences](https://docs.espressif.com/projects/arduino-esp32/en/latest/api/preferences.html),
[Espressif HTTPClient](https://github.com/espressif/arduino-esp32/blob/master/libraries/HTTPClient/src/HTTPClient.h),
[Let's Encrypt trust chains](https://letsencrypt.org/certificates/).
