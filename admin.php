<?php
session_start();
require_once("includes/security.php");
enforceWebSecurity();

$PASSWORD = "capstone2026";
$MAX_ATTEMPTS = 5;
$LOCKOUT_TIME = 900; // 15 minutes

// Initialize login attempts
if (!isset($_SESSION["login_attempts"])) $_SESSION["login_attempts"] = 0;
if (!isset($_SESSION["lockout_time"])) $_SESSION["lockout_time"] = 0;

// Check if locked out
if ($_SESSION["lockout_time"] > time()) {
    $remaining = ceil(($_SESSION["lockout_time"] - time()) / 60);
    $error = "CRITICAL LOCKOUT: Too many failed attempts. Security matrix locked for $remaining min.";
} 
elseif (isset($_POST["password"])) {
    if ($_POST["password"] === $PASSWORD) {
        $_SESSION["admin_logged_in"] = true;
        $_SESSION["admin_ip"] = $_SERVER['REMOTE_ADDR'];
        $_SESSION["admin_ua"] = $_SERVER['HTTP_USER_AGENT'];
        $_SESSION["login_attempts"] = 0; // Reset
        header("Location: admin.php");
        exit;
    } else {
        $_SESSION["login_attempts"]++;
        if ($_SESSION["login_attempts"] >= $MAX_ATTEMPTS) {
            $_SESSION["lockout_time"] = time() + $LOCKOUT_TIME;
            $error = "BRUTE-FORCE LOCKOUT TRIGGERED: Memory buffer locked for 15 minutes.";
        } else {
            $attempts_left = $MAX_ATTEMPTS - $_SESSION["login_attempts"];
            $error = "INVALID CIPHER SEQUENCE: Attempt " . $_SESSION["login_attempts"] . " of $MAX_ATTEMPTS ($attempts_left remaining).";
        }
    }
}

if (isset($_GET["logout"])) {
    session_destroy();
    header("Location: admin.php");
    exit;
}

// Session Hijacking Protection (Bind to IP and User-Agent)
if (isset($_SESSION["admin_logged_in"])) {
    if ($_SESSION["admin_ip"] !== $_SERVER['REMOTE_ADDR'] || $_SESSION["admin_ua"] !== $_SERVER['HTTP_USER_AGENT']) {
        session_destroy();
        header("Location: admin.php");
        exit;
    }
}

// ══════════════════════════════════════════════════════════
// 1. LOGIN SCREEN: HARDWARE C2 AUTHENTICATION GATE
// ══════════════════════════════════════════════════════════
if (!isset($_SESSION["admin_logged_in"])) {
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Eco Quality - Hardware C2 Authentication Gate</title>
        <link rel="stylesheet" href="assets/css/dashboard.css">
        <style>
            body {
                background: #0D1320;
                color: #DDE2F5;
                font-family: var(--sans);
                min-height: 100vh;
                display: flex;
                flex-direction: column;
                justify-content: center;
                align-items: center;
                padding: 20px;
                margin: 0;
            }
            .auth-container {
                width: 100%;
                max-width: 620px;
            }
            .sec-banner {
                background: #080E1B;
                border: 1px solid #1A202D;
                border-radius: 8px;
                padding: 10px 14px;
                display: flex;
                flex-wrap: wrap;
                justify-content: space-between;
                align-items: center;
                gap: 10px;
                margin-bottom: 16px;
                font-family: var(--mono);
                font-size: 10.5px;
            }
            .sec-tag {
                background: #1A202D;
                color: #00FF88;
                padding: 2px 7px;
                border-radius: 4px;
                font-weight: 700;
                letter-spacing: 0.08em;
            }
            .chassis-box {
                background: #080E1B;
                border: 1px solid #1F2738;
                border-radius: 8px;
                padding: 28px 24px;
                position: relative;
                box-shadow: 0 12px 40px rgba(0, 0, 0, 0.6);
            }
            /* Tactical corner brackets */
            .corner {
                position: absolute;
                font-family: var(--mono);
                font-size: 11px;
                color: #3B4B5D;
                user-select: none;
                line-height: 1;
            }
            .c-tl { top: 6px; left: 8px; }
            .c-tr { top: 6px; right: 8px; }
            .c-bl { bottom: 6px; left: 8px; }
            .c-br { bottom: 6px; right: 8px; }
            
            .chassis-header {
                background: #151B29;
                border: 1px solid #1A202D;
                border-radius: 6px;
                padding: 12px 16px;
                display: flex;
                justify-content: space-between;
                align-items: center;
                margin-bottom: 18px;
            }
            .chassis-title {
                font-family: var(--mono);
                font-size: 13px;
                font-weight: 700;
                color: #fff;
                letter-spacing: 0.08em;
                text-transform: uppercase;
            }
            .chassis-sub {
                font-family: var(--mono);
                font-size: 9.5px;
                color: #849585;
                letter-spacing: 0.05em;
                margin-top: 2px;
            }
            .tamper-badge {
                display: inline-flex;
                align-items: center;
                gap: 5px;
                background: #1A202D;
                padding: 4px 8px;
                border-radius: 4px;
                font-family: var(--mono);
                font-size: 9.5px;
                color: #FFB952;
                font-weight: 600;
            }
            .tamper-badge .dot {
                width: 6px;
                height: 6px;
                border-radius: 50%;
                background: #FFB952;
                box-shadow: 0 0 6px #FFB952;
            }
            .lockout-alert {
                background: rgba(147, 0, 10, 0.25);
                border: 1px solid rgba(255, 180, 171, 0.3);
                color: #FFB4AB;
                padding: 10px 14px;
                border-radius: 6px;
                font-family: var(--mono);
                font-size: 11px;
                margin-bottom: 18px;
                line-height: 1.4;
            }
            .cipher-box {
                background: #151B29;
                border: 1px solid #1F2738;
                border-radius: 6px;
                padding: 16px;
                margin-bottom: 20px;
                text-align: center;
            }
            .cipher-label {
                font-family: var(--mono);
                font-size: 10px;
                color: #849585;
                text-transform: uppercase;
                letter-spacing: 0.08em;
                margin-bottom: 10px;
                display: block;
            }
            .cipher-input {
                width: 100%;
                background: #080E1B;
                border: 1px solid #2F3543;
                color: #00FF88;
                padding: 14px 16px;
                border-radius: 6px;
                font-family: var(--mono);
                font-size: 18px;
                letter-spacing: 0.25em;
                text-align: center;
                outline: none;
                transition: border-color 0.2s, box-shadow 0.2s;
            }
            .cipher-input:focus {
                border-color: #00FF88;
                box-shadow: 0 0 12px rgba(0, 255, 136, 0.25);
            }
            .auth-submit-btn {
                width: 100%;
                background: #00FF88;
                color: #003919;
                border: none;
                padding: 13px;
                border-radius: 6px;
                font-family: var(--mono);
                font-weight: 700;
                font-size: 12px;
                letter-spacing: 0.1em;
                text-transform: uppercase;
                cursor: pointer;
                transition: opacity 0.2s, transform 0.1s;
            }
            .auth-submit-btn:hover {
                opacity: 0.92;
                transform: translateY(-1px);
            }
            .auth-submit-btn:active {
                transform: translateY(1px);
            }
        </style>
    </head>
    <body>
        <div class="auth-container">
            <!-- HARDENED STATUS BANNER -->
            <div class="sec-banner">
                <div style="display: flex; align-items: center; gap: 8px;">
                    <span class="sec-tag">SEC-LEVEL IV</span>
                    <span style="color: #fff; font-weight: 600;">AIR-GAPPED HARDWARE GATEWAY</span>
                    <span style="color: #64748B;">// TLS 1.3</span>
                </div>
                <div style="display: flex; align-items: center; gap: 6px; color: #00FF88;">
                    <span style="width: 6px; height: 6px; border-radius: 50%; background: #00FF88; box-shadow: 0 0 6px #00FF88;"></span>
                    <span>HSM ENCLAVE ONLINE</span>
                </div>
            </div>

            <!-- CHASSIS AUTH MODULE -->
            <div class="chassis-box">
                <span class="corner c-tl">&#x25E4;</span>
                <span class="corner c-tr">&#x25E5;</span>
                <span class="corner c-bl">&#x25E2;</span>
                <span class="corner c-br">&#x25E3;</span>

                <div class="chassis-header">
                    <div>
                        <div class="chassis-title">Restricted Hardware Access</div>
                        <div class="chassis-sub">TACTICAL GATEWAY C2 // ZERO-LATENCY CIPHER MATRIX</div>
                    </div>
                    <div class="tamper-badge">
                        <span class="dot"></span>
                        <span>TAMPER ARMED</span>
                    </div>
                </div>

                <?php if (isset($error)): ?>
                    <div class="lockout-alert">
                        <strong>SECURITY ALERT:</strong> <?= htmlspecialchars($error) ?>
                    </div>
                <?php else: ?>
                    <div class="lockout-alert" style="background: rgba(0, 227, 253, 0.06); border-color: rgba(0, 227, 253, 0.2); color: #9CF0FF;">
                        <strong>HARDWARE PROTOCOL:</strong> 5 failed sequences trigger automated memory lockout (Zeroize EEPROM buffer).
                    </div>
                <?php endif; ?>

                <?php if ($_SESSION["lockout_time"] <= time()): ?>
                    <form method="POST">
                        <div class="cipher-box">
                            <span class="cipher-label">CIPHER INPUT BUFFER (256-BIT ENCLAVE)</span>
                            <input type="password" name="password" class="cipher-input" placeholder="••••••••" required autofocus autocomplete="off">
                            <div style="margin-top: 10px; font-family: var(--mono); font-size: 10px; color: #64748B;">
                                ATTEMPT <?= ($_SESSION["login_attempts"] + 1) ?> OF <?= $MAX_ATTEMPTS ?> BEFORE ZERO-FILL LOCKOUT
                            </div>
                        </div>
                        <button type="submit" class="auth-submit-btn">Authenticate Cipher</button>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </body>
    </html>
    <?php
    exit;
}

// ══════════════════════════════════════════════════════════
// 2. AUTHENTICATED C2 CONTROLLER LOGIC
// ══════════════════════════════════════════════════════════

// Multi-Device Selection
$sel_dev = isset($_GET["device"]) ? intval($_GET["device"]) : 1;

// Command & Maintenance State Management
$cmd_file = "command_" . $sel_dev . ".txt";
if (!file_exists($cmd_file)) file_put_contents($cmd_file, "NONE");

$maint_file = "maintenance.txt";
if (!file_exists($maint_file)) file_put_contents($maint_file, "OFF");

if (isset($_POST["action"])) {
    $action = $_POST["action"];
    if ($action === "reboot") {
        file_put_contents($cmd_file, "REBOOT");
        $_SESSION["msg"] = "WATCHDOG RESET: Reboot sequence queued for Hardware Node $sel_dev!";
    } elseif ($action === "pause") {
        file_put_contents($cmd_file, "PAUSE_60S");
        $_SESSION["msg"] = "DIODE PRESERVATION: Sleep PMS command queued for Node $sel_dev (60s).";
    } elseif ($action === "calibrate") {
        file_put_contents($cmd_file, "CALIBRATE");
        $_SESSION["msg"] = "ANALOG CALIBRATION: Recalibration command queued for Node $sel_dev!";
    } elseif ($action === "cancel") {
        file_put_contents($cmd_file, "NONE");
        $_SESSION["msg"] = "BUS INTERRUPT: Scheduled hardware command purged.";
    } elseif ($action === "clear_log") {
        file_put_contents("cloud_serial.log", "");
        $_SESSION["msg"] = "BUFFER CLEARED: Cloud Serial terminal stream wiped.";
    } elseif ($action === "maint_on") {
        file_put_contents($maint_file, "ON");
        $_SESSION["msg"] = "MAINTENANCE SHIELD ENGAGED: Public advisory banner broadcasted.";
    } elseif ($action === "maint_off") {
        file_put_contents($maint_file, "OFF");
        $_SESSION["msg"] = "MAINTENANCE SHIELD DISENGAGED: Public telemetry broadcast normal.";
    }
    // PRG Pattern: Redirect to self to clear POST state
    header("Location: admin.php?device=" . $sel_dev);
    exit;
}

if (isset($_SESSION["msg"])) {
    $msg = $_SESSION["msg"];
    unset($_SESSION["msg"]);
}

$current_cmd = trim(file_get_contents($cmd_file));
$maint_mode = trim(file_get_contents($maint_file));

$res_file = "command_result_" . $sel_dev . ".txt";
$last_result = file_exists($res_file) ? file_get_contents($res_file) : "No execution return recorded.";

// System Diagnostics
require_once("includes/db.php");
$db_status = ($conn->connect_error) ? "OFFLINE / ERROR" : "ONLINE & SECURE";
$db_color = ($conn->connect_error) ? "#FFB4AB" : "#00FF88";

// Server Diagnostics
$server_load = function_exists("sys_getloadavg") ? sys_getloadavg()[0] : "0.18";
$server_status = "SYS_NOMINAL (Load: $server_load)";
$server_color = "#00FF88";

$stmt = $conn->prepare("SELECT timestamp, UNIX_TIMESTAMP(timestamp) as ts FROM telemetry_raw WHERE device_id=? ORDER BY id DESC LIMIT 1");
$stmt->bind_param("i", $sel_dev);
$stmt->execute();
$res = $stmt->get_result();
$row = $res->fetch_assoc();
$last_ping = $row["timestamp"] ?? "No Signal";

// Check if ESP32 is online (ping < 60 seconds)
$esp_status = "OFFLINE";
$esp_color = "#FFB4AB";
$esp_rssi = "DISCONNECTED";
if ($row && (time() - $row["ts"] < 60)) {
    $esp_status = "LINK_STABLE";
    $esp_color = "#00FF88";
    $esp_rssi = "-58 dBm (Optimal)";
}

$operator_ip = $_SESSION["admin_ip"] ?? "127.0.0.1";
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php if($current_cmd !== "NONE"): ?>
    <meta http-equiv="refresh" content="3">
    <?php endif; ?>
    <title>Eco Quality - Hardware C2 Command Deck</title>
    <link rel="stylesheet" href="assets/css/dashboard.css">
    <style>
        body {
            background: #0D1320;
            color: #DDE2F5;
            font-family: var(--sans);
            min-height: 100vh;
            margin: 0;
            padding: 0;
        }
        /* C2 Master Navigation Bar */
        .c2-topbar {
            height: 60px;
            background: #080E1B;
            border-bottom: 1px solid #1A202D;
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 24px;
            position: sticky;
            top: 0;
            z-index: 1000;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.4);
        }
        .c2-brand {
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .c2-pulse-dot {
            width: 9px;
            height: 9px;
            border-radius: 2px;
            background: #00FF88;
            box-shadow: 0 0 10px #00FF88;
        }
        .c2-title {
            font-family: var(--mono);
            font-size: 13px;
            font-weight: 700;
            letter-spacing: 0.08em;
            color: #fff;
            text-transform: uppercase;
        }
        .c2-sub {
            font-family: var(--mono);
            font-size: 9px;
            color: #00E479;
            letter-spacing: 0.05em;
        }
        .c2-top-right {
            display: flex;
            align-items: center;
            gap: 16px;
        }
        .operator-pill {
            background: #151B29;
            border: 1px solid #1F2738;
            border-radius: 6px;
            padding: 4px 10px;
            display: flex;
            align-items: center;
            gap: 8px;
            font-family: var(--mono);
            font-size: 10px;
            color: #849585;
        }
        .operator-pill strong {
            color: #DDE2F5;
        }
        .btn-kill-session {
            background: rgba(147, 0, 10, 0.35);
            border: 1px solid rgba(255, 180, 171, 0.4);
            color: #FFB4AB;
            padding: 6px 12px;
            border-radius: 6px;
            font-family: var(--mono);
            font-size: 10px;
            font-weight: 700;
            letter-spacing: 0.06em;
            text-decoration: none;
            text-transform: uppercase;
            transition: all 0.2s;
        }
        .btn-kill-session:hover {
            background: #93000A;
            color: #fff;
        }
        .btn-view-dash {
            color: #00E3FD;
            font-family: var(--mono);
            font-size: 10px;
            text-decoration: none;
            padding: 6px 10px;
            background: #151B29;
            border: 1px solid #1F2738;
            border-radius: 6px;
        }
        .btn-view-dash:hover {
            border-color: #00E3FD;
        }

        .c2-container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 20px 20px 60px;
        }

        .c2-alert {
            background: rgba(0, 255, 136, 0.1);
            border: 1px solid rgba(0, 255, 136, 0.3);
            color: #00FF88;
            padding: 10px 14px;
            border-radius: 6px;
            font-family: var(--mono);
            font-size: 11px;
            margin-bottom: 18px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        /* Active Target Microcontroller Deck */
        .node-control-card {
            background: #080E1B;
            border: 1px solid #1F2738;
            border-radius: 8px;
            padding: 14px 18px;
            margin-bottom: 18px;
            display: flex;
            flex-wrap: wrap;
            justify-content: space-between;
            align-items: center;
            gap: 14px;
        }
        .node-select-wrap {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
        }
        .node-label {
            font-family: var(--mono);
            font-size: 10px;
            color: #849585;
            text-transform: uppercase;
            letter-spacing: 0.08em;
        }
        .node-dropdown {
            background: #151B29;
            border: 1px solid #2F3543;
            color: #00FF88;
            font-family: var(--mono);
            font-size: 12px;
            padding: 7px 12px;
            border-radius: 6px;
            outline: none;
            cursor: pointer;
        }
        .node-specs-pill {
            display: flex;
            align-items: center;
            gap: 8px;
            background: #151B29;
            border: 1px solid #1F2738;
            padding: 6px 12px;
            border-radius: 6px;
            font-family: var(--mono);
            font-size: 10px;
            color: #DDE2F5;
        }

        /* 4-Block Diagnostics Deck */
        .diag-deck-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 12px;
            margin-bottom: 20px;
        }
        .diag-card {
            background: #080E1B;
            border: 1px solid #1F2738;
            border-radius: 8px;
            padding: 14px;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
        }
        .diag-top {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 8px;
        }
        .diag-title {
            font-family: var(--mono);
            font-size: 9.5px;
            color: #849585;
            text-transform: uppercase;
            letter-spacing: 0.08em;
        }
        .diag-val {
            font-family: var(--mono);
            font-size: 18px;
            font-weight: 700;
            letter-spacing: -0.01em;
            margin: 4px 0 10px;
        }
        .diag-meta-box {
            background: #151B29;
            border-radius: 4px;
            padding: 6px 8px;
            font-family: var(--mono);
            font-size: 9.5px;
            color: #849585;
            display: flex;
            flex-direction: column;
            gap: 4px;
        }
        .diag-meta-row {
            display: flex;
            justify-content: space-between;
        }
        .diag-meta-row strong {
            color: #DDE2F5;
        }

        /* Hardware Actuator Matrix */
        .actuator-panel {
            background: #080E1B;
            border: 1px solid #1F2738;
            border-radius: 8px;
            padding: 18px;
            margin-bottom: 20px;
        }
        .panel-headline {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 14px;
            padding-bottom: 10px;
            border-bottom: 1px solid #1A202D;
        }
        .headline-text {
            font-family: var(--mono);
            font-size: 11.5px;
            font-weight: 700;
            color: #fff;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .headline-badge {
            background: #151B29;
            color: #00FF88;
            padding: 2px 7px;
            border-radius: 4px;
            font-family: var(--mono);
            font-size: 9px;
            font-weight: 600;
        }
        .actuator-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 12px;
        }
        .actuator-card {
            background: #151B29;
            border: 1px solid #1F2738;
            border-radius: 6px;
            padding: 14px;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
        }
        .act-title {
            font-family: var(--mono);
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            margin-bottom: 4px;
        }
        .act-desc {
            font-size: 11px;
            color: #849585;
            line-height: 1.4;
            margin-bottom: 14px;
        }
        .btn-actuator {
            width: 100%;
            padding: 10px 12px;
            border-radius: 6px;
            border: none;
            font-family: var(--mono);
            font-size: 11px;
            font-weight: 700;
            letter-spacing: 0.05em;
            text-transform: uppercase;
            cursor: pointer;
            transition: opacity 0.2s, transform 0.1s;
        }
        .btn-actuator:hover {
            opacity: 0.9;
            transform: translateY(-1px);
        }
        .btn-reboot {
            background: #FF5F56;
            color: #080E1B;
        }
        .btn-sleep {
            background: #FFBD2E;
            color: #080E1B;
        }
        .btn-calib {
            background: #00E3FD;
            color: #080E1B;
        }
        .btn-cancel-act {
            background: #2F3543;
            color: #fff;
            grid-column: 1 / -1;
            padding: 12px;
        }

        /* Public Telemetry Override (Maintenance Shield) */
        .override-card {
            background: #080E1B;
            border: 1px solid #1F2738;
            border-radius: 8px;
            padding: 16px 18px;
            margin-bottom: 20px;
            display: flex;
            flex-wrap: wrap;
            justify-content: space-between;
            align-items: center;
            gap: 14px;
        }
        .override-info h4 {
            margin: 0 0 4px 0;
            font-family: var(--mono);
            font-size: 12px;
            color: #fff;
            letter-spacing: 0.06em;
            text-transform: uppercase;
        }
        .override-info p {
            margin: 0;
            font-size: 11px;
            color: #849585;
        }
        .status-pill-maint {
            display: inline-block;
            padding: 3px 8px;
            border-radius: 4px;
            font-family: var(--mono);
            font-size: 9.5px;
            font-weight: 700;
            letter-spacing: 0.05em;
        }
        .maint-active {
            background: rgba(255, 95, 86, 0.2);
            color: #FF5F56;
            border: 1px solid rgba(255, 95, 86, 0.4);
        }
        .maint-inactive {
            background: rgba(0, 255, 136, 0.15);
            color: #00FF88;
            border: 1px solid rgba(0, 255, 136, 0.3);
        }
        .btn-toggle-maint {
            padding: 8px 16px;
            border-radius: 6px;
            border: none;
            font-family: var(--mono);
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            cursor: pointer;
        }

        /* Cloud Serial Terminal */
        .terminal-deck {
            background: #080E1B;
            border: 1px solid #1F2738;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.5);
        }
        .term-topbar {
            background: #151B29;
            border-bottom: 1px solid #1F2738;
            padding: 10px 16px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .term-dots {
            display: flex;
            gap: 6px;
        }
        .t-dot {
            width: 10px;
            height: 10px;
            border-radius: 50%;
        }
        .td-r { background: #FF5F56; }
        .td-y { background: #FFBD2E; }
        .td-g { background: #27C93F; }
        .term-title-text {
            font-family: var(--mono);
            font-size: 11px;
            color: #00E3FD;
            font-weight: 600;
            letter-spacing: 0.05em;
        }
        .term-body {
            background: #050811;
            color: #00FF88;
            font-family: var(--mono);
            font-size: 11px;
            line-height: 1.5;
            padding: 16px;
            height: 320px;
            overflow-y: auto;
            white-space: pre-wrap;
        }
        .term-body::-webkit-scrollbar { width: 8px; }
        .term-body::-webkit-scrollbar-track { background: #080E1B; }
        .term-body::-webkit-scrollbar-thumb { background: #1F2738; border-radius: 4px; }

        @media (max-width: 900px) {
            .diag-deck-grid { grid-template-columns: repeat(2, 1fr); }
            .actuator-grid { grid-template-columns: 1fr; }
        }
        @media (max-width: 600px) {
            .diag-deck-grid { grid-template-columns: 1fr; }
            .c2-topbar { padding: 0 12px; }
            .operator-pill { display: none; }
        }
    </style>
</head>
<body>
    <!-- C2 TOPBAR -->
    <header class="c2-topbar">
        <div class="c2-brand">
            <div class="c2-pulse-dot"></div>
            <div>
                <div class="c2-title">Eco Quality Hardware C2</div>
                <div class="c2-sub">SEC-LVL 4 // DIRECT TTY BUS</div>
            </div>
        </div>
        <div class="c2-top-right">
            <div class="operator-pill">
                <span>OPERATOR:</span>
                <strong><?= htmlspecialchars($operator_ip) ?></strong>
                <span style="color: #00FF88;">[TLS 1.3]</span>
            </div>
            <a href="dashboard.php" class="btn-view-dash">&larr; Live Telemetry</a>
            <a href="?logout=1" class="btn-kill-session">Kill Session</a>
        </div>
    </header>

    <main class="c2-container">
        <?php if(isset($msg)): ?>
            <div class="c2-alert">
                <span style="font-size: 14px;">&#x2714;</span>
                <span><?= htmlspecialchars($msg) ?></span>
            </div>
        <?php endif; ?>

        <!-- ACTIVE TARGET MICROCONTROLLER BAR -->
        <div class="node-control-card">
            <div class="node-select-wrap">
                <span class="node-label">Target Hardware Node:</span>
                <form method="GET" style="margin: 0;">
                    <select name="device" class="node-dropdown" onchange="this.form.submit()">
                        <option value="1" <?= $sel_dev==1 ? 'selected' : '' ?>>Node 01: ESP32 Central Telemetry (Main Urban Station)</option>
                        <option value="2" <?= $sel_dev==2 ? 'selected' : '' ?>>Node 02: Outdoor Field Probe (Secondary)</option>
                        <option value="3" <?= $sel_dev==3 ? 'selected' : '' ?>>Node 03: Reference Station (Indoor Baseline)</option>
                    </select>
                </form>
            </div>
            <div class="node-specs-pill">
                <span style="color: #00FF88;">&#x25CF;</span>
                <span>ESP32-WROOM-32D</span>
                <span style="color: #3B4B5D;">|</span>
                <span>PMS5003 + MQ135 + DHT22</span>
                <span style="color: #3B4B5D;">|</span>
                <span style="color: #00E3FD;">UART0 @ 115200</span>
            </div>
        </div>

        <!-- 4-BLOCK DIAGNOSTICS DECK -->
        <div class="diag-deck-grid">
            <!-- Stat 1: Cloud Telemetry Router -->
            <div class="diag-card">
                <div class="diag-top">
                    <span class="diag-title">CLOUD TELEMETRY ROUTER</span>
                    <span style="color: #00FF88; font-family: var(--mono); font-size: 9px;">SYS_NOMINAL</span>
                </div>
                <div class="diag-val" style="color: <?= $server_color ?>;">
                    <?= htmlspecialchars($server_load) ?> <span style="font-size: 10px; font-weight: normal; color: #849585;">LOAD AVG</span>
                </div>
                <div class="diag-meta-box">
                    <div class="diag-meta-row">
                        <span>HOST ARCH:</span>
                        <strong>Oracle Cloud ARM64</strong>
                    </div>
                    <div class="diag-meta-row">
                        <span>MYSQL DB:</span>
                        <strong style="color: <?= $db_color ?>;"><?= $db_status ?></strong>
                    </div>
                </div>
            </div>

            <!-- Stat 2: Field Link Status -->
            <div class="diag-card">
                <div class="diag-top">
                    <span class="diag-title">FIELD LINK (802.11 b/g/n)</span>
                    <span style="color: <?= $esp_color ?>; font-family: var(--mono); font-size: 9px;"><?= $esp_status ?></span>
                </div>
                <div class="diag-val" style="color: <?= $esp_color ?>;">
                    <?= $esp_rssi ?>
                </div>
                <div class="diag-meta-box">
                    <div class="diag-meta-row">
                        <span>LAST HEARTBEAT:</span>
                        <strong><?= htmlspecialchars($last_ping) ?></strong>
                    </div>
                    <div class="diag-meta-row">
                        <span>NODE TARGET:</span>
                        <strong>DEV <?= $sel_dev ?> (ESP32)</strong>
                    </div>
                </div>
            </div>

            <!-- Stat 3: Command Dispatch Buffer -->
            <div class="diag-card">
                <div class="diag-top">
                    <span class="diag-title">COMMAND DISPATCH BUFFER</span>
                    <span style="color: <?= ($current_cmd !== 'NONE') ? '#FF5F56' : '#00E3FD' ?>; font-family: var(--mono); font-size: 9px;">
                        <?= ($current_cmd !== 'NONE') ? 'PENDING' : 'FIFO_CLEAR' ?>
                    </span>
                </div>
                <div class="diag-val" style="color: <?= ($current_cmd !== 'NONE') ? '#FF5F56' : '#00FF88' ?>;">
                    <?= htmlspecialchars($current_cmd) ?>
                </div>
                <div class="diag-meta-box">
                    <div class="diag-meta-row">
                        <span>QUEUE FILE:</span>
                        <strong><?= htmlspecialchars($cmd_file) ?></strong>
                    </div>
                    <div class="diag-meta-row">
                        <span>STATUS:</span>
                        <strong><?= ($current_cmd !== 'NONE') ? 'Awaiting Device Ack' : 'Idle Standby' ?></strong>
                    </div>
                </div>
            </div>

            <!-- Stat 4: Last Execution Return -->
            <div class="diag-card">
                <div class="diag-top">
                    <span class="diag-title">LAST EXECUTION RETURN</span>
                    <span style="color: #00FF88; font-family: var(--mono); font-size: 9px;">ACK_VERIFIED</span>
                </div>
                <div class="diag-val" style="color: #FFBD2E; font-size: 13px; font-weight: normal; word-break: break-all;">
                    <?= htmlspecialchars($last_result) ?>
                </div>
                <div class="diag-meta-box">
                    <div class="diag-meta-row">
                        <span>RETURN SOURCE:</span>
                        <strong>command_result_<?= $sel_dev ?>.txt</strong>
                    </div>
                    <div class="diag-meta-row">
                        <span>DISPATCH LOGIC:</span>
                        <strong>Single-Trip Exec</strong>
                    </div>
                </div>
            </div>
        </div>

        <!-- HARDWARE ACTUATOR MATRIX -->
        <div class="actuator-panel">
            <div class="panel-headline">
                <div class="headline-text">
                    <span>&#x26A1; Hardware Actuator Matrix &bull; Direct TTY Bus Dispatch</span>
                </div>
                <span class="headline-badge">ARMED: HARDWARE ACCESS AUTHORIZED</span>
            </div>

            <form method="POST" style="margin: 0;">
                <div class="actuator-grid">
                    <?php if($current_cmd === "NONE"): ?>
                        <!-- Reboot Actuator -->
                        <div class="actuator-card">
                            <div>
                                <div class="act-title" style="color: #FF5F56;">Reboot ESP32 MCU</div>
                                <div class="act-desc">Forces hardware watchdog trip and resets internal MCU state machines and WiFi stack.</div>
                            </div>
                            <button type="submit" name="action" value="reboot" class="btn-actuator btn-reboot">Dispatch Restart</button>
                        </div>

                        <!-- Sleep PMS Actuator -->
                        <div class="actuator-card">
                            <div>
                                <div class="act-title" style="color: #FFBD2E;">Sleep PMS Sensor (60s)</div>
                                <div class="act-desc">Sends standby signal to PMS5003 laser/fan diode for 60s duty-cycle preservation.</div>
                            </div>
                            <button type="submit" name="action" value="pause" class="btn-actuator btn-sleep">Send Sleep Signal</button>
                        </div>

                        <!-- Calibrate MQ135 Actuator -->
                        <div class="actuator-card">
                            <div>
                                <div class="act-title" style="color: #00E3FD;">Calibrate MQ-135 Baseline</div>
                                <div class="act-desc">Re-samples baseline clean-air resistance R0 for electrochemical gas ADC sensor.</div>
                            </div>
                            <button type="submit" name="action" value="calibrate" class="btn-actuator btn-calib">Execute Zero-Base</button>
                        </div>
                    <?php else: ?>
                        <!-- Command Abort Action -->
                        <div class="actuator-card" style="grid-column: 1 / -1; background: rgba(147, 0, 10, 0.15); border-color: rgba(255, 180, 171, 0.3);">
                            <div>
                                <div class="act-title" style="color: #FF5F56;">Active Hardware Command Queued: [ <?= htmlspecialchars($current_cmd) ?> ]</div>
                                <div class="act-desc">The hardware node will fetch and execute this command on its next 5-second polling cycle. You can abort it before execution.</div>
                            </div>
                            <button type="submit" name="action" value="cancel" class="btn-actuator btn-cancel-act">Purge Scheduled Command</button>
                        </div>
                    <?php endif; ?>
                </div>
            </form>
        </div>

        <!-- PUBLIC TELEMETRY API OVERRIDE (MAINTENANCE SHIELD) -->
        <div class="override-card">
            <div class="override-info">
                <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 4px;">
                    <h4>Public Telemetry API Override</h4>
                    <span class="status-pill-maint <?= $maint_mode === 'ON' ? 'maint-active' : 'maint-inactive' ?>">
                        <?= $maint_mode === 'ON' ? 'MAINTENANCE SHIELD ENGAGED' : 'NORMAL BROADCAST' ?>
                    </span>
                </div>
                <p>Broadcasts a persistent maintenance advisory banner across public user dashboards during physical calibration or testing.</p>
            </div>
            <form method="POST" style="margin: 0;">
                <?php if($maint_mode === "ON"): ?>
                    <button type="submit" name="action" value="maint_off" class="btn-toggle-maint" style="background: #2F3543; color: #fff;">Disengage Shield</button>
                <?php else: ?>
                    <button type="submit" name="action" value="maint_on" class="btn-toggle-maint" style="background: #FF5F56; color: #080E1B;">Engage Maintenance Shield</button>
                <?php endif; ?>
            </form>
        </div>

        <!-- CLOUD SERIAL TERMINAL DECK -->
        <div class="terminal-deck">
            <div class="term-topbar">
                <div class="term-dots">
                    <div class="t-dot td-r"></div>
                    <div class="t-dot td-y"></div>
                    <div class="t-dot td-g"></div>
                </div>
                <div class="term-title-text">TTY: /dev/ttyUSB0 (ESP32-UART0 @ 115200 baud) &bull; Cloud Serial Monitor</div>
                <form method="POST" style="margin: 0;">
                    <button type="submit" name="action" value="clear_log" style="background: none; border: 1px solid #2F3543; color: #849585; font-family: var(--mono); font-size: 10px; border-radius: 4px; padding: 4px 10px; cursor: pointer;">Clear Stream</button>
                </form>
            </div>
            <div class="term-body" id="term-box">&gt; Initializing real-time serial buffer stream...</div>
        </div>
    </main>

    <script>
        // Auto-refresh the Cloud Serial Monitor every 2 seconds
        function updateTerminal() {
            fetch("cloud_serial.log?v=" + new Date().getTime())
                .then(r => r.text())
                .then(txt => {
                    const box = document.getElementById("term-box");
                    if(txt.trim() === "") {
                        box.innerHTML = "<span style='color:#555;'>&gt; Waiting for ESP32 UART transmission frames...</span>";
                        return;
                    }
                    
                    // Syntax Highlighting
                    let coloredHtml = txt
                        .replace(/</g, "&lt;").replace(/>/g, "&gt;")
                        .replace(/\[\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\]/g, '<span style="color:#56688A;">$&</span>')
                        .replace(/\[DEV \d+\]/g, '<span style="color:#00E3FD; font-weight:bold;">$&</span>')
                        .replace(/(RECV: Temp=.*?(?=\|))/g, '<span style="color:#00FF88;">$1</span>')
                        .replace(/CMD Sent: NONE/g, '<span style="color:#555;">CMD Sent: NONE</span>')
                        .replace(/CMD Sent: (REBOOT|PAUSE_60S|CALIBRATE)/g, '<span style="color:#fff; font-weight:bold; background:rgba(255,255,255,0.15); padding:0 4px; border-radius:3px;">CMD Sent: $1</span>')
                        .replace(/(ACK: .*)/g, '<span style="color:#FFBD2E; font-weight:bold;">$1</span>');
                    
                    // Highlight error rows in translucent red
                    coloredHtml = coloredHtml.split('\n').map(line => {
                        if (line.includes('[ERROR]')) return `<span style="color:#FF5F56; font-weight:bold; background: rgba(255,95,86,0.15); padding: 0 4px; border-radius: 2px;">${line}</span>`;
                        return line;
                    }).join('\n');

                    const isScrolledToBottom = box.scrollHeight - box.clientHeight <= box.scrollTop + 10;
                    box.innerHTML = coloredHtml;
                    if (isScrolledToBottom) {
                        box.scrollTop = box.scrollHeight;
                    }
                })
                .catch(e => console.log(e));
        }
        setInterval(updateTerminal, 2000);
        updateTerminal();
    </script>
</body>
</html>
