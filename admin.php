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
    $error = "Too many failed attempts. Locked out for $remaining minutes.";
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
            $error = "Too many failed attempts. Locked out for 15 minutes.";
        } else {
            $error = "Incorrect password. Attempt " . $_SESSION["login_attempts"] . " of $MAX_ATTEMPTS.";
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

if (!isset($_SESSION["admin_logged_in"])) {
    echo "<!DOCTYPE html><html><head><meta name=\"viewport\" content=\"width=device-width, initial-scale=1.0\"><title>Admin Login</title><link rel=\"stylesheet\" href=\"assets/css/dashboard.css\"></head><body style=\"display:flex;justify-content:center;align-items:center;height:100vh;flex-direction:column;\">";
    echo "<div class=\"card\" style=\"width: 300px; text-align: center;\">";
    echo "<h2 style=\"margin-bottom:20px; font-family:var(--sans);\">Admin Access</h2>";
    if (isset($error)) echo "<p style=\"color:red; font-size:12px; margin-bottom:10px;\">$error</p>";
    if ($_SESSION["lockout_time"] <= time()) {
        echo "<form method=\"POST\"><input type=\"password\" name=\"password\" placeholder=\"Password\" style=\"padding:10px; width:100%; margin-bottom:15px; border-radius:5px; border:1px solid #ccc;\"><br><button type=\"submit\" class=\"sb-btn\" style=\"width:100%; border:none; cursor:pointer;\">Login</button></form>";
    }
    echo "</div></body></html>";
    exit;
}

// Multi-Device Select
$sel_dev = isset($_GET["device"]) ? intval($_GET["device"]) : 1;

// C2 Logic & Maintenance
$cmd_file = "command_" . $sel_dev . ".txt";
if (!file_exists($cmd_file)) file_put_contents($cmd_file, "NONE");

$maint_file = "maintenance.txt";
if (!file_exists($maint_file)) file_put_contents($maint_file, "OFF");

if (isset($_POST["action"])) {
    $action = $_POST["action"];
    if ($action === "reboot") {
        file_put_contents($cmd_file, "REBOOT");
        $_SESSION["msg"] = "Reboot command queued for Device $sel_dev!";
    } elseif ($action === "pause") {
        file_put_contents($cmd_file, "PAUSE_60S");
        $_SESSION["msg"] = "Pause command queued! Device $sel_dev will pause telemetry for 60s.";
    } elseif ($action === "calibrate") {
        file_put_contents($cmd_file, "CALIBRATE");
        $_SESSION["msg"] = "Calibration command queued for Device $sel_dev!";
    } elseif ($action === "cancel") {
        file_put_contents($cmd_file, "NONE");
        $_SESSION["msg"] = "Command cancelled.";
    } elseif ($action === "clear_log") {
        file_put_contents("cloud_serial.log", "");
        $_SESSION["msg"] = "Cloud Serial Log cleared.";
    } elseif ($action === "maint_on") {
        file_put_contents($maint_file, "ON");
        $_SESSION["msg"] = "Maintenance Mode ENABLED on the public dashboard.";
    } elseif ($action === "maint_off") {
        file_put_contents($maint_file, "OFF");
        $_SESSION["msg"] = "Maintenance Mode DISABLED. Dashboard is back to normal.";
    }
    // PRG Pattern: Redirect to self to clear POST state
    header("Location: admin.php?device=" . $sel_dev);
    exit;
}

if (isset($_SESSION["msg"])) {
    $msg = $_SESSION["msg"];
    unset($_SESSION["msg"]);
}

$current_cmd = file_get_contents($cmd_file);
$maint_mode = trim(file_get_contents($maint_file));

$res_file = "command_result_" . $sel_dev . ".txt";
$last_result = file_exists($res_file) ? file_get_contents($res_file) : "No results yet.";

// System Diagnostics
require_once("includes/db.php");
$db_status = ($conn->connect_error) ? "OFFLINE / ERROR" : "ONLINE & SECURE";
$db_color = ($conn->connect_error) ? "var(--danger)" : "var(--accent)";

// Server Diagnostics
$server_load = function_exists("sys_getloadavg") ? sys_getloadavg()[0] : "N/A";
$server_status = "ONLINE (Load: $server_load)";
$server_color = "var(--blue)";

$stmt = $conn->prepare("SELECT timestamp, UNIX_TIMESTAMP(timestamp) as ts FROM telemetry_raw WHERE device_id=? ORDER BY id DESC LIMIT 1");
$stmt->bind_param("i", $sel_dev);
$stmt->execute();
$res = $stmt->get_result();
$row = $res->fetch_assoc();
$last_ping = $row["timestamp"] ?? "No Data Yet";

// Check if ESP32 is online (ping < 60 seconds)
$esp_status = "OFFLINE";
$esp_color = "var(--danger)";
if ($row && (time() - $row["ts"] < 60)) {
    $esp_status = "ONLINE & TRANSMITTING";
    $esp_color = "var(--accent)";
}

// File Permissions Check
$fs_status = is_writable($cmd_file) ? "READ/WRITE OK" : "READ-ONLY ERROR";
$fs_color = is_writable($cmd_file) ? "var(--accent)" : "var(--danger)";

?>
<!DOCTYPE html>
<html>
<head>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php if($current_cmd !== "NONE"): ?>
    <meta http-equiv="refresh" content="3">
    <?php endif; ?>
    <title>Eco Quality - Command & Control</title>
    <link rel="stylesheet" href="assets/css/dashboard.css">
    <style>
        .terminal {
            background: #0c0c0c;
            color: #0f0;
            font-family: var(--mono);
            font-size: 11px;
            padding: 15px;
            border-radius: 8px;
            height: 250px;
            overflow-y: auto;
            white-space: pre-wrap;
            border: 1px solid #333;
            box-shadow: inset 0 0 10px rgba(0,0,0,0.8);
        }
        .terminal-header {
            display: flex; justify-content: space-between;
            font-family: var(--sans); font-size: 11px; color: #888;
            margin-bottom: 5px; text-transform: uppercase;
        }
        .diag-grid {
            display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-top: 10px;
        }
        .diag-box {
            background: var(--surface); padding: 10px; border-radius: 6px; border: 1px solid var(--border);
            font-family: var(--mono); font-size: 11px;
        }
        @media (max-width: 600px) { .diag-grid { grid-template-columns: 1fr; } }
    </style>
</head>
<body style="padding: 20px;">
    <div class="topbar" style="margin-bottom: 20px;">
        <h1 style="color:var(--text);">Admin Control Panel</h1>
        <a href="?logout=1" style="color:var(--danger); text-decoration:none; font-weight:bold; font-size: 14px;">Logout</a>
    </div>

    <?php if(isset($msg)) echo "<div style=\"background:var(--accent); color:#fff; padding:10px; border-radius:5px; margin-bottom:20px; font-family:var(--sans); font-size:14px;\">$msg</div>"; ?>

    <div class="cards" style="display:block;">
        
        <!-- DEVICE SELECTOR -->
        <div class="card" style="margin-bottom: 20px;">
            <div class="card-label">Select Target Device</div>
            <form method="GET" style="margin-top: 10px; display:flex; gap:10px;">
                <select name="device" onchange="this.form.submit()" style="flex:1; padding:10px; border-radius:5px; border:1px solid var(--border); font-family:var(--sans); background:var(--surface); color:var(--text);">
                    <option value="1" <?php if($sel_dev==1) echo "selected"; ?>>Device 1 (Main Sensor Node)</option>
                    <option value="2" <?php if($sel_dev==2) echo "selected"; ?>>Device 2 (Outdoor Node)</option>
                    <option value="3" <?php if($sel_dev==3) echo "selected"; ?>>Device 3 (Indoor Node)</option>
                </select>
            </form>
        </div>

        <!-- DIAGNOSTICS MODULE -->
        <div class="card" style="margin-bottom: 20px;">
            <div class="card-label">System Diagnostics</div>
            <div class="diag-grid">
                <div class="diag-box">
                    <div style="color:var(--muted); margin-bottom:5px;">CLOUD SERVER</div>
                    <div style="color:<?php echo $server_color; ?>; font-weight:bold; font-size:13px;">-? <?php echo $server_status; ?></div>
                </div>
                <div class="diag-box">
                    <div style="color:var(--muted); margin-bottom:5px;">HARDWARE NODE (DEV <?php echo $sel_dev; ?>)</div>
                    <div style="color:<?php echo $esp_color; ?>; font-weight:bold; font-size:13px;">-? <?php echo $esp_status; ?></div>
                    <div style="margin-top:5px; color:#888;">Last Ping: <?php echo $last_ping; ?></div>
                </div>
                <div class="diag-box">
                    <div style="color:var(--muted); margin-bottom:5px;">QUEUED CMD (DEV <?php echo $sel_dev; ?>)</div>
                    <div style="color:<?php echo ($current_cmd!=="NONE") ? "var(--danger)" : "var(--accent)"; ?>; font-weight:bold; font-size:13px;">[ <?php echo $current_cmd; ?> ]</div>
                </div>
                <div class="diag-box">
                    <div style="color:var(--muted); margin-bottom:5px;">LAST CMD RESULT</div>
                    <div style="color:var(--warn); font-weight:bold; font-size:11px; word-wrap:break-word;"><?php echo $last_result; ?></div>
                </div>
            </div>
        </div>

        <!-- C2 REMOTE CONTROLS -->
        <div class="card" style="margin-bottom: 20px;">
            <div class="card-label">Hardware Controls (Device <?php echo $sel_dev; ?>)</div>
            <form method="POST" style="margin-top: 15px; display:flex; flex-direction:column; gap:10px;">
                <?php if($current_cmd === "NONE"): ?>
                    <div style="display:flex; gap:10px; width:100%;">
                        <button type="submit" name="action" value="reboot" title="Triggers a hardware-level restart of the ESP32." style="background:var(--danger); color:#fff; padding:12px 20px; border:none; border-radius:5px; font-weight:bold; cursor:pointer; flex:1; font-size:11px;">REBOOT ESP32</button>
                        <button type="submit" name="action" value="pause" title="Sends a hex command to power down the PMS5003 laser to extend hardware lifespan, then wakes it to flush the chamber." style="background:var(--warn); color:#fff; padding:12px 20px; border:none; border-radius:5px; font-weight:bold; cursor:pointer; flex:1; font-size:11px;">SLEEP PMS5003 (60s)</button>
                        <button type="submit" name="action" value="calibrate" title="Takes 10 rapid readings of clean air to reset the MQ135 baseline offset." style="background:var(--blue); color:#fff; padding:12px 20px; border:none; border-radius:5px; font-weight:bold; cursor:pointer; flex:1; font-size:11px;">CALIBRATE MQ135</button>
                    </div>
                <?php else: ?>
                    <button type="submit" name="action" value="cancel" style="background:#888; color:#fff; padding:12px 20px; border:none; border-radius:5px; font-weight:bold; cursor:pointer; width:100%;">CANCEL ACTIVE COMMAND</button>
                <?php endif; ?>
            </form>
        </div>

        <!-- PUBLIC DASHBOARD CONTROLS -->
        <div class="card" style="margin-bottom: 20px;">
            <div class="card-label">Public Dashboard Controls</div>
            <div style="display:flex; justify-content:space-between; align-items:center; margin-top: 10px;">
                <div style="font-size:13px; font-family:var(--sans);">
                    Maintenance Mode Alert
                    <div style="font-size:11px; color:var(--muted); margin-top:4px;">Displays a yellow warning banner on the main website.</div>
                </div>
                <form method="POST" style="margin:0;">
                    <?php if($maint_mode === "ON"): ?>
                        <button type="submit" name="action" value="maint_off" style="background:#888; color:#fff; padding:8px 16px; border:none; border-radius:5px; font-weight:bold; cursor:pointer;">DISABLE</button>
                    <?php else: ?>
                        <button type="submit" name="action" value="maint_on" style="background:var(--danger); color:#fff; padding:8px 16px; border:none; border-radius:5px; font-weight:bold; cursor:pointer;">ENABLE</button>
                    <?php endif; ?>
                </form>
            </div>
            <?php if($maint_mode === "ON"): ?>
            <div style="margin-top:15px; padding:10px; background:rgba(240, 82, 82, 0.1); border:1px solid var(--danger); color:var(--danger); border-radius:5px; font-size:12px; font-weight:bold; text-align:center;">
                ⚠️ ALERT IS CURRENTLY ACTIVE ON PUBLIC DASHBOARD ⚠️
            </div>
            <?php endif; ?>
        </div>

        <!-- CLOUD SERIAL MONITOR -->
        <div class="card" style="margin-bottom: 20px; padding:0; overflow:hidden; border:none;">
            <div style="background:#1a1a1a; padding:10px 15px; border-bottom:1px solid #333; display:flex; justify-content:space-between; align-items:center;">
                <div style="color:#aaa; font-family:var(--sans); font-size:12px; font-weight:bold;">Cloud Serial Monitor (All Devices)</div>
                <form method="POST" style="margin:0;"><button type="submit" name="action" value="clear_log" style="background:none; border:1px solid #555; color:#aaa; font-size:10px; border-radius:3px; padding:3px 8px; cursor:pointer;">Clear</button></form>
            </div>
            <div class="terminal" id="term-box">Loading live data...</div>
        </div>
        
        <div style="margin-top: 20px; text-align: center;">
            <a href="dashboard.php" class="sb-btn" style="text-decoration:none; display:inline-block;">Return to Dashboard</a>
        </div>
    </div>

    <script>
        // Auto-refresh the Cloud Serial Monitor every 2 seconds
        function updateTerminal() {
            fetch("cloud_serial.log?v=" + new Date().getTime())
                .then(r => r.text())
                .then(txt => {
                    const box = document.getElementById("term-box");
                    if(txt.trim() === "") {
                        box.innerHTML = "<span style='color:#555;'>> Waiting for ESP32 transmission...</span>";
                        return;
                    }
                    
                    // Syntax Highlighting
                    let coloredHtml = txt
                        .replace(/</g, "&lt;").replace(/>/g, "&gt;") // Escape HTML
                        .replace(/\[\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\]/g, '<span style="color:#666;">$&</span>') // Timestamp
                        .replace(/\[DEV \d+\]/g, '<span style="color:#4C9EEB;">$&</span>') // Device ID
                        .replace(/(RECV: Temp=.*?(?=\|))/g, '<span style="color:#00CFA8;">$1</span>') // Readings (Green)
                        .replace(/CMD Sent: NONE/g, '<span style="color:#666;">CMD Sent: NONE</span>') // Idle CMD (Gray)
                        .replace(/CMD Sent: (REBOOT|PAUSE_60S|CALIBRATE)/g, '<span style="color:#fff; font-weight:bold; background:rgba(255,255,255,0.2); padding:0 4px; border-radius:3px;">CMD Sent: $1</span>') // Active CMD (White)
                        .replace(/(ACK: .*)/g, '<span style="color:var(--warn); font-weight:bold;">$1</span>'); // ACKs (Yellow)
                    
                    // Highlight whole line if it contains ERROR
                    coloredHtml = coloredHtml.split('\n').map(line => {
                        if (line.includes('[ERROR]')) return `<span style="color:#F05252; font-weight:bold;">${line}</span>`;
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
        updateTerminal(); // Load immediately
    </script>
</body>
</html>
