# ESP32 update needed before production activation

The updated Arduino sketch is in `firmware/eco_quality_esp32/eco_quality_esp32.ino`.
It retains DHT22 GPIO4, MQ135 GPIO34, PMS5003 RX16/TX17 and the five-second normal
sampling interval. Its private upload copy must be kept outside the web root.

Required transport changes:

1. Use the verified HTTPS origin `https://eco-quality.duckdns.org/ai_quality/`.
2. Configure the ESP32 TLS client's trusted root CA and synchronize its clock so
   it can validate the server certificate. Never disable certificate verification.
3. Send `Authorization: Bearer <device token>` on every request to `insert.php`.
   Store the token in a private firmware configuration, not in published code.
4. Include the registered `device_id` and all sensor parameters (`temp`, `hum`,
   `pm10`, `mq135`). Values outside configured limits receive HTTP 422.
5. Handle HTTP 401 as an authentication/configuration error and 429 with backoff.
   Do not rapidly retry a rejected request.
6. Parse `command` and `command_id` from successful telemetry responses. Store
   processed IDs persistently and make command handling idempotent, including
   reboot recovery. Commands may be retried after 60 seconds.
7. Acknowledge that exact ID using the same bearer header:
   `insert.php?device_id=1&command_id=123&msg=done`. Sensor readings are not needed.
   The server rejects acknowledgements for another device. Retrying an already
   acknowledged command succeeds without changing the recorded result, so a lost
   HTTP response cannot trap the board in an acknowledgement loop.

Only one command per device is in flight at a time. Completion survives board restarts
through NVS. An interrupted pause or calibration is reported as interrupted instead
of being blindly replayed. Reboot is acknowledged after boot. Hardware actions and
flash writes cannot be one atomic transaction; the firmware prioritizes avoiding
repeated side effects and reports interruptions honestly.

The production bearer token must be provisioned during the coordinated server and
firmware release. The newly generated local token is only for local development.
