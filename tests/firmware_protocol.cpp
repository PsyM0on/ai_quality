// Compile-time tests run with the installed ESP32 compiler; no physical board required.
#include "../firmware/eco_quality_esp32/protocol.h"

constexpr bool testPms() {
    uint8_t frame[32] = {};
    frame[0] = 0x42; frame[1] = 0x4D; frame[3] = 28;
    frame[12] = 0; frame[13] = 11;  // Atmospheric PM2.5 must NOT be reported as PM10.
    frame[14] = 1; frame[15] = 44;  // PM10 = 300.
    uint16_t sum = 0;
    for (size_t i = 0; i < 30; ++i) sum += frame[i];
    frame[30] = uint8_t(sum >> 8); frame[31] = uint8_t(sum);
    uint16_t output = 0;
    if (!decodePmsFrame(frame, 32, output) || output != 300) return false;
    if (decodePmsFrame(frame, 31, output)) return false;
    frame[15]++; // checksum mismatch
    if (decodePmsFrame(frame, 32, output)) return false;
    frame[15]--; frame[3] = 29;
    if (decodePmsFrame(frame, 32, output)) return false;
    frame[3] = 28; frame[0] = 0;
    return !decodePmsFrame(frame, 32, output);
}

constexpr bool testCommands() {
    CommandState state = {};
    state.id = 123;
    if (canStartCommand(state, 123) || canStartCommand(state, 122)) return false;
    if (!canStartCommand(state, 124)) return false;
    state.awaitingAck = 1;
    return !canStartCommand(state, 124);
}

static_assert(testPms(), "PMS frame/checksum/PM10 decoding regression");
static_assert(testCommands(), "Command deduplication/in-flight regression");
