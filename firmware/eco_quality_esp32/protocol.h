#pragma once
#include <stdint.h>
#include <stddef.h>

// Full 32-byte Plantower frame: atmospheric PM10 is at offsets 14 and 15.
inline constexpr bool decodePmsFrame(const uint8_t *frame, size_t size, uint16_t &pm10) {
    if (size != 32 || frame[0] != 0x42 || frame[1] != 0x4D ||
        frame[2] != 0 || frame[3] != 28) return false;
    uint16_t sum = 0;
    for (size_t i = 0; i < 30; ++i) sum += frame[i];
    if (sum != (uint16_t(frame[30]) << 8 | frame[31])) return false;
    pm10 = uint16_t(frame[14]) << 8 | frame[15];
    return true;
}

enum CommandKind : uint8_t { NO_COMMAND, REBOOT, PAUSE, CALIBRATE };
enum CommandPhase : uint8_t { IDLE, STARTED, DONE };
struct CommandState {
    uint32_t version;
    uint32_t deviceId;
    uint64_t id;
    int32_t baseline;
    CommandKind kind;
    CommandPhase phase;
    uint8_t awaitingAck;
    uint8_t reserved;
    char result[100];
};

inline constexpr bool canStartCommand(const CommandState &state, uint64_t id) {
    return id > state.id && !state.awaitingAck;
}
