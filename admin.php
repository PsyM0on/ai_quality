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

// LOGIN SCREEN UI
if (!isset($_SESSION["admin_logged_in"])) {
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Admin Login - Eco Quality</title>
        <link rel="stylesheet" href="assets/css/dashboard.css">
        <style>
            body { display: flex; justify-content: center; align-items: center; min-height: 100vh; padding: 20px; }
            .login-panel { width: 100%; max-width: 360px; padding: 32px 24px; text-align: center; }
            .login-title { font-family: var(--sans); font-size: 20px; font-weight: 700; margin-bottom: 8px; color: var(--text); }
            .login-sub { font-family: var(--sans); font-size: 12px; color: var(--muted); margin-bottom: 24px; }
            .login-input { width: 100%; background: var(--surface); border: 1px solid var(--border2); color: var(--text); padding: 12px 14px; border-radius: 8px; font-family: var(--mono); font-size: 14px; margin-bottom: 16px; transition: border-color 0.2s; text-align: center; }
            .login-input:focus { outline: none; border-color: var(--accent); }
            .login-btn { width: 100%; background: var(--accent); color: #111; border: none; padding: 12px; border-radius: 8px; font-family: var(--sans); font-weight: 700; font-size: 13px; text-transform: uppercase; letter-spacing: 0.05em; cursor: pointer; transition: opacity 0.2s; }
            .login-btn:hover { opacity: 0.9; }
            .login-err { background: rgba(240, 82, 82, 0.1); color: var(--danger); border: 1px solid rgba(240, 82, 82, 0.3); padding: 10px; border-radius: 6px; font-size: 12px; margin-bottom: 16px; }
        </style>
    </head>
    <body>
        <div class="panel login-panel">
            <div class="login-title">Command & Control</div>
            <div class="login-sub">Authorized personnel only</div>
            
            <?php if (isset($error)): ?>
                <div class="login-err"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <?php if ($_SESSION["lockout_time"] <= time()): ?>
                <form method="POST">
                    <input type="password" name="password" class="login-input" placeholder="Enter Passcode" required autofocus>
                    <button type="submit" class="login-btn">Authenticate</button>
                </form>
            <?php endif; ?>
        </div>
        <script>if (localStorage.getItem('aq-theme') === 'light') document.body.classList.add('light');</script>
    </body>
    </html>
    <?php
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
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php if($current_cmd !== "NONE"): ?>
    <meta http-equiv="refresh" content="3">
    <?php endif; ?>
    <title>Eco Quality - C2 Admin</title>
    <link rel="stylesheet" href="assets/css/dashboard.css">
    <style>
        body { padding-top: 10px; }
        .admin-container { max-width: 900px; margin: 0 auto; padding: 20px; padding-bottom: calc(20px + env(safe-area-inset-bottom, 0px)); }
        .admin-alert { background: rgba(0, 207, 168, 0.1); color: var(--accent); border: 1px solid rgba(0, 207, 168, 0.3); padding: 12px 16px; border-radius: 8px; margin-bottom: 24px; font-family: var(--sans); font-size: 13px; font-weight: 600; display: flex; align-items: center; gap: 8px; }
        
        .diag-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 12px; padding: 18px; }
        .diag-box { background: var(--surface); padding: 14px; border-radius: 8px; border: 1px solid var(--border); font-family: var(--mono); }
        .diag-label { color: var(--muted); margin-bottom: 6px; font-size: 10px; text-transform: uppercase; letter-spacing: 0.1em; }
        .diag-value { font-weight: bold; font-size: 13px; display: flex; align-items: center; gap: 6px; }
        .diag-sub { margin-top: 6px; color: var(--soft); font-size: 11px; }

        .btn-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 12px; padding: 18px; }
        .cmd-btn { padding: 14px 16px; border: none; border-radius: 8px; font-weight: 700; font-family: var(--sans); font-size: 12px; letter-spacing: 0.05em; cursor: pointer; color: #fff; transition: opacity 0.2s, transform 0.1s; text-transform: uppercase; }
        .cmd-btn:hover { opacity: 0.9; transform: translateY(-1px); }
        .cmd-btn:active { transform: translateY(1px); }
        .btn-reboot { background: var(--danger); box-shadow: 0 4px 12px rgba(240, 82, 82, 0.2); }
        .btn-sleep { background: var(--warn); box-shadow: 0 4px 12px rgba(245, 166, 35, 0.2); }
        .btn-calib { background: var(--blue); box-shadow: 0 4px 12px rgba(76, 158, 235, 0.2); }
        .btn-cancel { background: var(--border2); color: var(--text); width: 100%; grid-column: 1 / -1; }

        .dev-select { width: 100%; padding: 12px 14px; border-radius: 8px; border: 1px solid var(--border2); font-family: var(--sans); font-size: 14px; background: var(--surface); color: var(--text); outline: none; transition: border-color 0.2s; margin-bottom: 4px; }
        .dev-select:focus { border-color: var(--accent); }

        .terminal-wrap { border-radius: 12px; overflow: hidden; background: #07090F; border: 1px solid #333; margin-top: 24px; box-shadow: 0 10px 30px rgba(0,0,0,0.5); }
        .terminal-header { background: #161A26; padding: 10px 16px; border-bottom: 1px solid #333; display: flex; justify-content: space-between; align-items: center; }
        .mac-dots { display: flex; gap: 6px; }
        .mac-dot { width: 10px; height: 10px; border-radius: 50%; }
        .dot-r { background: #FF5F56; } .dot-y { background: #FFBD2E; } .dot-g { background: #27C93F; }
        .term-title { color: #8892B0; font-family: var(--mono); font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.1em; }
        
        .terminal { color: #0f0; font-family: var(--mono); font-size: 11.5px; padding: 16px; height: 300px; overflow-y: auto; white-space: pre-wrap; line-height: 1.5; }
        .terminal::-webkit-scrollbar { width: 8px; }
        .terminal::-webkit-scrollbar-track { background: #0A0D14; }
        .terminal::-webkit-scrollbar-thumb { background: #333; border-radius: 4px; }
        .terminal::-webkit-scrollbar-thumb:hover { background: #555; }

        .maint-box { padding: 18px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 16px; }
        .maint-text { font-family: var(--sans); }
        .maint-title { font-size: 14px; font-weight: 600; margin-bottom: 4px; }
        .maint-desc { font-size: 12px; color: var(--muted); }
        
        .badge { padding: 4px 10px; border-radius: 4px; font-size: 11px; font-weight: bold; letter-spacing: 0.05em; background: rgba(255,255,255,0.1); }
        .badge.on { background: rgba(240, 82, 82, 0.15); color: var(--danger); border: 1px solid rgba(240, 82, 82, 0.3); }

        @media (max-width: 700px) {
            .diag-grid { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
    <div class="topbar">
        <div style="display: flex; align-items: center; gap: 12px;">
            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="color:var(--accent);"><path d="M12 20h9"></path><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"></path></svg>
            <h1 style="color:var(--text); font-size: 18px;">Admin C2</h1>
        </div>
        <div style="display: flex; align-items: center; gap: 16px;">
            <a href="dashboard.php" class="menu-link" style="font-size:12px;">Dashboard</a>
            <a href="?logout=1" style="color:var(--danger); text-decoration:none; font-weight:bold; font-size: 12px; padding: 6px 12px; background: rgba(240,82,82,0.1); border-radius: 6px;">Logout</a>
        </div>
    </div>

    <div class="admin-container">
        <?php if(isset($msg)): ?>
            <div class="admin-alert">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
                <?= htmlspecialchars($msg) ?>
            </div>
        <?php endif; ?>

        <!-- DEVICE SELECTOR -->
        <div class="panel" style="margin-bottom: 20px;">
            <div class="panel-header">
                <div class="panel-title" style="display:flex; align-items:center; gap:6px;">
                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="3" width="20" height="14" rx="2" ry="2"></rect><line x1="8" y1="21" x2="16" y2="21"></line><line x1="12" y1="17" x2="12" y2="21"></line></svg>
                    Target Device
                </div>
            </div>
            <div style="padding: 18px;">
                <form method="GET" style="margin: 0;">
                    <select name="device" class="dev-select" onchange="this.form.submit()">
                        <option value="1" <?= $sel_dev==1 ? 'selected' : '' ?>>Device 1 — Main Sensor Node (ESP32)</option>
                        <option value="2" <?= $sel_dev==2 ? 'selected' : '' ?>>Device 2 — Outdoor Node (Secondary)</option>
                        <option value="3" <?= $sel_dev==3 ? 'selected' : '' ?>>Device 3 — Indoor Node (Reference)</option>
                    </select>
                </form>
            </div>
        </div>

        <!-- DIAGNOSTICS -->
        <div class="panel" style="margin-bottom: 20px;">
            <div class="panel-header">
                <div class="panel-title" style="display:flex; align-items:center; gap:6px;">
                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"></polyline></svg>
                    System Diagnostics
                </div>
            </div>
            <div class="diag-grid">
                <div class="diag-box">
                    <div class="diag-label">Cloud Server (Oracle)</div>
                    <div class="diag-value" style="color: <?= $server_color ?>;">
                        <span style="font-size: 16px;">•</span> <?= htmlspecialchars($server_status) ?>
                    </div>
                </div>
                <div class="diag-box">
                    <div class="diag-label">Hardware Node (DEV <?= $sel_dev ?>)</div>
                    <div class="diag-value" style="color: <?= $esp_color ?>;">
                        <span style="font-size: 16px;">•</span> <?= htmlspecialchars($esp_status) ?>
                    </div>
                    <div class="diag-sub">Last Ping: <?= htmlspecialchars($last_ping) ?></div>
                </div>
                <div class="diag-box">
                    <div class="diag-label">Queued Command (DEV <?= $sel_dev ?>)</div>
                    <div class="diag-value" style="color: <?= ($current_cmd !== 'NONE') ? 'var(--danger)' : 'var(--accent)' ?>;">
                        [ <?= htmlspecialchars($current_cmd) ?> ]
                    </div>
                </div>
                <div class="diag-box">
                    <div class="diag-label">Last Command Result</div>
                    <div class="diag-value" style="color: var(--warn); font-size: 11px; font-weight: normal; word-wrap: break-word;">
                        <?= htmlspecialchars($last_result) ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- HARDWARE CONTROLS -->
        <div class="panel" style="margin-bottom: 20px;">
            <div class="panel-header">
                <div class="panel-title" style="display:flex; align-items:center; gap:6px;">
                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12.55a11 11 0 0 1 14.08 0"></path><path d="M1.42 9a16 16 0 0 1 21.16 0"></path><path d="M8.53 16.11a6 6 0 0 1 6.95 0"></path><line x1="12" y1="20" x2="12.01" y2="20"></line></svg>
                    Hardware Controls (Device <?= $sel_dev ?>)
                </div>
            </div>
            <form method="POST" style="margin: 0;">
                <div class="btn-grid">
                    <?php if($current_cmd === "NONE"): ?>
                        <button type="submit" name="action" value="reboot" class="cmd-btn btn-reboot" title="Triggers a hardware-level restart of the ESP32.">Reboot ESP32</button>
                        <button type="submit" name="action" value="pause" class="cmd-btn btn-sleep" title="Powers down the PMS5003 laser to extend lifespan.">Sleep PMS (60s)</button>
                        <button type="submit" name="action" value="calibrate" class="cmd-btn btn-calib" title="Takes 10 rapid readings of clean air to reset MQ135 baseline.">Calibrate MQ135</button>
                    <?php else: ?>
                        <button type="submit" name="action" value="cancel" class="cmd-btn btn-cancel">Cancel Active Command</button>
                    <?php endif; ?>
                </div>
            </form>
        </div>

        <!-- PUBLIC DASHBOARD CONTROLS -->
        <div class="panel">
            <div class="panel-header">
                <div class="panel-title" style="display:flex; align-items:center; gap:6px;">
                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z"></path><path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z"></path></svg>
                    Public Dashboard Configuration
                </div>
                <?php if($maint_mode === "ON"): ?>
                    <span class="badge on">ACTIVE</span>
                <?php else: ?>
                    <span class="badge">OFF</span>
                <?php endif; ?>
            </div>
            <div class="maint-box">
                <div class="maint-text">
                    <div class="maint-title">Maintenance Mode Banner</div>
                    <div class="maint-desc">Displays a yellow warning banner on the main website to inform users of ongoing calibration or downtime.</div>
                </div>
                <form method="POST" style="margin: 0;">
                    <?php if($maint_mode === "ON"): ?>
                        <button type="submit" name="action" value="maint_off" class="cmd-btn btn-cancel" style="width: auto;">Disable Banner</button>
                    <?php else: ?>
                        <button type="submit" name="action" value="maint_on" class="cmd-btn btn-reboot" style="width: auto;">Enable Banner</button>
                    <?php endif; ?>
                </form>
            </div>
        </div>

        <!-- CLOUD SERIAL TERMINAL -->
        <div class="terminal-wrap">
            <div class="terminal-header">
                <div class="mac-dots">
                    <div class="mac-dot dot-r"></div>
                    <div class="mac-dot dot-y"></div>
                    <div class="mac-dot dot-g"></div>
                </div>
                <div class="term-title">Cloud Serial Monitor (All Nodes)</div>
                <form method="POST" style="margin:0;">
                    <button type="submit" name="action" value="clear_log" style="background:none; border:1px solid #333; color:#8892B0; font-family:var(--sans); font-size:10px; border-radius:4px; padding:4px 8px; cursor:pointer; transition: background 0.2s;">Clear Log</button>
                </form>
            </div>
            <div class="terminal" id="term-box">Loading live data...</div>
        </div>
    </div>

    <script>
        if (localStorage.getItem('aq-theme') === 'light') document.body.classList.add('light');

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
                        .replace(/\[\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\]/g, '<span style="color:#56688A;">$&</span>') // Timestamp
                        .replace(/\[DEV \d+\]/g, '<span style="color:#4C9EEB; font-weight:bold;">$&</span>') // Device ID
                        .replace(/(RECV: Temp=.*?(?=\|))/g, '<span style="color:#00CFA8;">$1</span>') // Readings (Green)
                        .replace(/CMD Sent: NONE/g, '<span style="color:#555;">CMD Sent: NONE</span>') // Idle CMD (Gray)
                        .replace(/CMD Sent: (REBOOT|PAUSE_60S|CALIBRATE)/g, '<span style="color:#fff; font-weight:bold; background:rgba(255,255,255,0.15); padding:0 4px; border-radius:3px;">CMD Sent: $1</span>') // Active CMD (White)
                        .replace(/(ACK: .*)/g, '<span style="color:var(--warn); font-weight:bold;">$1</span>'); // ACKs (Yellow)
                    
                    // Highlight whole line if it contains ERROR
                    coloredHtml = coloredHtml.split('\n').map(line => {
                        if (line.includes('[ERROR]')) return `<span style="color:#F05252; font-weight:bold; background: rgba(240,82,82,0.1); padding: 0 4px; border-radius: 2px;">${line}</span>`;
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
