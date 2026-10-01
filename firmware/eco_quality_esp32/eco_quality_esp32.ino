// ESP32 Arduino core 3.x. Sensor pins and 5-second sampling match the original sketch.
#include <WiFi.h>
#include <WiFiClientSecure.h>
#include <HTTPClient.h>
#include <ArduinoJson.h>
#include <DHT.h>
#include <HardwareSerial.h>
#include <Preferences.h>
#include <esp_task_wdt.h>
#include <time.h>
#include <math.h>
#include "protocol.h"
#include "trust_anchor.h"
#if __has_include("secrets.h")
#include "secrets.h"
#else
#include "secrets.example.h"
#endif

constexpr uint8_t DHT_PIN = 4, MQ135_PIN = 34, PMS_RX = 16, PMS_TX = 17;
constexpr uint32_t NORMAL_INTERVAL_MS = 5000, PM_MAX_AGE_MS = 15000;
DHT dht(DHT_PIN, DHT22);
HardwareSerial pmsSerial(2);
Preferences preferences;
CommandState state = {};
uint16_t currentPM10 = 0;
bool havePm = false;
uint32_t lastPmAt = 0, lastAttemptAt = 0, retryIntervalMs = NORMAL_INTERVAL_MS;
uint32_t lastReconnectAt = 0;
bool configured = false;

void pollPms() {
    static uint8_t frame[32];
    static size_t used = 0;
    static uint32_t lastByteAt = 0;
    if (used && uint32_t(millis() - lastByteAt) > 1000) used = 0;
    while (pmsSerial.available()) {
        uint8_t byte = uint8_t(pmsSerial.read());
        lastByteAt = millis();
        if (used == 0 && byte != 0x42) continue;
        if (used == 1 && byte != 0x4D) {
            used = byte == 0x42 ? 1 : 0;
            continue;
        }
        frame[used++] = byte;
        if (used == sizeof(frame)) {
            uint16_t value;
            if (decodePmsFrame(frame, sizeof(frame), value)) {
                currentPM10 = value;
                havePm = true;
                lastPmAt = millis();
            }
            used = 0;
        }
    }
}

void servicedDelay(uint32_t duration) {
    uint32_t start = millis();
    while (uint32_t(millis() - start) < duration) {
        esp_task_wdt_reset();
        pollPms();
        delay(20);
    }
}

void haltDevice(const char *message) {
    Serial.println(message);
    while (true) { esp_task_wdt_reset(); delay(1000); }
}

bool saveState() {
    return preferences.putBytes("command", &state, sizeof(state)) == sizeof(state);
}

void finishCommand(const char *result) {
    state.phase = DONE;
    state.awaitingAck = 1;
    strlcpy(state.result, result, sizeof(state.result));
    if (!saveState()) haltDevice("[ERROR] Cannot save command result. Manual recovery required.");
}

void setPmsAwake(bool awake) {
    const uint8_t sleepCommand[] = {0x42, 0x4D, 0xE4, 0, 0, 1, 0x73};
    const uint8_t wakeCommand[] = {0x42, 0x4D, 0xE4, 0, 1, 1, 0x74};
    pmsSerial.write(awake ? wakeCommand : sleepCommand, 7);
    havePm = false;
}

String encodeQuery(const char *text) {
    const char hex[] = "0123456789ABCDEF";
    String encoded;
    for (const unsigned char *p = reinterpret_cast<const unsigned char *>(text); *p; ++p) {
        if ((*p >= 'a' && *p <= 'z') || (*p >= 'A' && *p <= 'Z') ||
            (*p >= '0' && *p <= '9') || *p == '-' || *p == '_' || *p == '.') encoded += char(*p);
        else { encoded += '%'; encoded += hex[*p >> 4]; encoded += hex[*p & 15]; }
    }
    return encoded;
}

int requestServer(const String &query, String &body) {
    body = "";
    WiFiClientSecure tls;
    tls.setCACert(AQ_ROOT_CA);
    tls.setHandshakeTimeout(15);
    HTTPClient http;
    http.setConnectTimeout(10000);
    http.setTimeout(10000);
    http.setFollowRedirects(HTTPC_DISABLE_FOLLOW_REDIRECTS);
    // HTTP/1.0 prevents chunk framing when reading a bounded response body.
    http.useHTTP10(true);
    if (!http.begin(tls, String(AQ_SERVER_URL) + "?device_id=" + String(AQ_DEVICE_ID) + query)) return -1;
    http.addHeader("Authorization", String("Bearer ") + AQ_DEVICE_TOKEN);
    http.addHeader("Accept", "application/json");
    esp_task_wdt_reset();
    int status = http.GET();
    if (status == HTTP_CODE_OK) {
        uint32_t start = millis();
        int expected = http.getSize();
        if (expected > 4096) status = -1;
        while (status == HTTP_CODE_OK && (tls.connected() || tls.available()) &&
               (expected < 0 || int(body.length()) < expected)) {
            while (tls.available()) {
                if (body.length() >= 4096) { status = -1; break; }
                body += char(tls.read());
            }
            if (uint32_t(millis() - start) >= 15000) { status = -1; break; }
            esp_task_wdt_reset();
            delay(1);
        }
        if (expected >= 0 && int(body.length()) != expected) status = -1;
    }
    http.end();
    esp_task_wdt_reset();
    return status;
}

void scheduleRetry(int status, bool validResponse) {
    if (status == 200 && validResponse) retryIntervalMs = NORMAL_INTERVAL_MS;
    else if (status == 401 || status == 403) {
        retryIntervalMs = 300000;
        Serial.println("[ERROR] Authentication rejected. Check device provisioning.");
    } else if (status == 429) retryIntervalMs = 60000;
    else retryIntervalMs = min(uint32_t(300000), max(uint32_t(10000), retryIntervalMs * 2));
    if (status != 200 || !validResponse) Serial.printf("[WARN] Request failed (%d); retry in %lu seconds.\n", status, (unsigned long)(retryIntervalMs / 1000));
    lastAttemptAt = millis();
}

void acknowledgePending() {
    char id[24]; snprintf(id, sizeof(id), "%llu", (unsigned long long)state.id);
    String response;
    int status = requestServer(String("&command_id=") + id + "&msg=" + encodeQuery(state.result), response);
    JsonDocument doc;
    bool received = status == 200 && !deserializeJson(doc, response) && doc["status"] == "ack_received";
    if (received) {
        state.awaitingAck = 0;
        if (!saveState()) haltDevice("[ERROR] Cannot save acknowledgement state.");
        Serial.println("[OK] Command acknowledged.");
    }
    scheduleRetry(status, received);
}

void handleCommand(JsonDocument &doc) {
    const char *command = doc["command"] | "NONE";
    if (strcmp(command, "NONE") == 0) return;
    if (!doc["command_id"].is<uint64_t>()) { Serial.println("[WARN] Invalid command ID."); return; }
    uint64_t id = doc["command_id"].as<uint64_t>();
    if (!canStartCommand(state, id)) { Serial.println("[INFO] Duplicate or out-of-order command ignored."); return; }
    CommandKind kind = NO_COMMAND;
    if (strcmp(command, "REBOOT") == 0) kind = REBOOT;
    else if (strcmp(command, "PAUSE_60S") == 0) kind = PAUSE;
    else if (strcmp(command, "CALIBRATE") == 0) kind = CALIBRATE;
    if (kind == NO_COMMAND) { Serial.println("[WARN] Unknown command ignored."); return; }

    // Persist intent BEFORE the side effect; reboots cannot replay an accepted command.
    state.id = id;
    state.kind = kind;
    state.phase = STARTED;
    state.awaitingAck = 1;
    state.result[0] = '\0';
    if (!saveState()) haltDevice("[ERROR] Cannot persist command; refusing to execute.");
    if (kind == REBOOT) {
        Serial.println("[ADMIN] Restarting; completion will be acknowledged after boot.");
        ESP.restart();
        return;
    }
    if (kind == PAUSE) {
        setPmsAwake(false);
        servicedDelay(60000);
        setPmsAwake(true);
        // Discard old UART frames; allow the fan/laser to stabilize before publishing.
        while (pmsSerial.available()) pmsSerial.read();
        servicedDelay(30000);
        finishCommand("PMS5003 pause finished; wake and warm-up commands sent");
    } else {
        long total = 0;
        for (int i = 0; i < 10; ++i) { total += analogRead(MQ135_PIN); servicedDelay(200); }
        state.baseline = total / 10;
        char result[100]; snprintf(result, sizeof(result), "MQ135 baseline set to %ld", (long)state.baseline);
        finishCommand(result);
    }
}

void setup() {
    Serial.begin(115200);
    esp_task_wdt_config_t watchdog = {};
    watchdog.timeout_ms = 120000;
    watchdog.idle_core_mask = 0;
    watchdog.trigger_panic = true;
    esp_err_t status = esp_task_wdt_init(&watchdog);
    if (status == ESP_ERR_INVALID_STATE) status = esp_task_wdt_reconfigure(&watchdog);
    if (status != ESP_OK) { Serial.println("[ERROR] Watchdog initialization failed."); while (true) delay(1000); }
    if (esp_task_wdt_status(nullptr) != ESP_OK && esp_task_wdt_add(nullptr) != ESP_OK)
        haltDevice("[ERROR] Cannot subscribe task to watchdog.");

    configured = strlen(AQ_WIFI_SSID) > 0 && strlen(AQ_DEVICE_TOKEN) >= 32 &&
                 AQ_DEVICE_ID > 0 && String(AQ_SERVER_URL).startsWith("https://");
    if (!configured) haltDevice("[SETUP] Supply secrets.h in the private sketch folder.");
    if (!preferences.begin("aq-cmd-v1", false)) haltDevice("[ERROR] Cannot open persistent command state.");
    size_t stored = preferences.getBytesLength("command");
    if (stored != 0) {
        if (stored != sizeof(state) || preferences.getBytes("command", &state, sizeof(state)) != sizeof(state) ||
            state.version != 1 || state.deviceId != AQ_DEVICE_ID || state.baseline < 0 || state.baseline > 4095 ||
            state.phase > DONE || state.kind > CALIBRATE || state.awaitingAck > 1 || state.result[99] != '\0')
            haltDevice("[ERROR] Invalid stored state or changed device ID; review before clearing NVS.");
    } else {
        state.version = 1; state.deviceId = AQ_DEVICE_ID;
        if (!saveState()) haltDevice("[ERROR] Cannot initialize command state.");
    }
    dht.begin();
    analogReadResolution(12);
    pmsSerial.begin(9600, SERIAL_8N1, PMS_RX, PMS_TX);
    setPmsAwake(true);
    if (state.phase == STARTED) {
        if (state.kind == REBOOT) finishCommand("Boot completed after reboot command");
        else finishCommand("Command interrupted by restart; sensor awakened; action not repeated");
    }
    WiFi.mode(WIFI_STA);
    WiFi.setAutoReconnect(true);
    WiFi.begin(AQ_WIFI_SSID, AQ_WIFI_PASSWORD);
    configTime(0, 0, "pool.ntp.org", "time.google.com");
    servicedDelay(30000);
    lastAttemptAt = millis() - NORMAL_INTERVAL_MS;
    Serial.println("[OK] Ready; waiting for Wi-Fi, clock and valid sensor readings.");
}

void loop() {
    esp_task_wdt_reset();
    pollPms();
    if (WiFi.status() != WL_CONNECTED) {
        if (uint32_t(millis() - lastReconnectAt) >= 30000) { WiFi.reconnect(); lastReconnectAt = millis(); }
        delay(20); return;
    }
    // Certificate validity cannot be checked until SNTP establishes a plausible clock.
    if (time(nullptr) < 1704067200 || uint32_t(millis() - lastAttemptAt) < retryIntervalMs) { delay(20); return; }
    lastAttemptAt = millis();
    if (state.awaitingAck && state.phase == DONE) { acknowledgePending(); return; }

    float temperature = dht.readTemperature(), humidity = dht.readHumidity();
    int gas = max<int32_t>(0, int32_t(analogRead(MQ135_PIN)) - state.baseline);
    if (!isfinite(temperature) || !isfinite(humidity) || temperature < -20 || temperature > 80 ||
        humidity < 0 || humidity > 100 || !havePm || uint32_t(millis() - lastPmAt) > PM_MAX_AGE_MS || currentPM10 > 600) {
        Serial.println("[WARN] Invalid or stale sensor reading; telemetry skipped.");
        return;
    }
    String response;
    String query = "&temp=" + String(temperature, 1) + "&hum=" + String(humidity, 1) +
                   "&mq135=" + String(gas) + "&pm10=" + String(currentPM10);
    int status = requestServer(query, response);
    JsonDocument doc;
    bool valid = status == 200 && !deserializeJson(doc, response) && doc["status"] == "ok";
    scheduleRetry(status, valid);
    if (valid) { Serial.println("[OK] Telemetry accepted."); handleCommand(doc); }
}
