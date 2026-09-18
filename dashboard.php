<?php
require_once("includes/security.php");
enforceWebSecurity();

// Fetch database records
require_once("includes/db.php");

$maint_file = "maintenance.txt";
$maint_mode = file_exists($maint_file) ? trim(file_get_contents($maint_file)) : "OFF";

if (isset($_GET['fetch'])) {
    header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
    header("Pragma: no-cache");
    header("Content-Type: application/json");
    $result = $conn->query("SELECT * FROM telemetry_raw ORDER BY id DESC LIMIT 20");
    $data = [];
    while ($row = $result->fetch_assoc()) $data[] = $row;
    echo json_encode($data);
    exit;
}

if (isset($_GET['latest'])) {
    header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
    header("Pragma: no-cache");
    header("Content-Type: application/json");
    $result = $conn->query("SELECT *, UNIX_TIMESTAMP(timestamp) as ts_unix, UNIX_TIMESTAMP() as now_unix FROM telemetry_raw ORDER BY id DESC LIMIT 1");
    $latest = $result ? $result->fetch_assoc() : null;
    
    if ($latest) {
        // Compute 24-Hour Rolling Average for PM10 (RA 8749 Compliance Standard)
        $avg_res = $conn->query("SELECT AVG(pm10) as pm10_24h, COUNT(*) as count_24h FROM telemetry_raw WHERE `timestamp` >= NOW() - INTERVAL 24 HOUR");
        $avg_row = $avg_res ? $avg_res->fetch_assoc() : null;
        $pm10_24h = ($avg_row && $avg_row['pm10_24h'] !== null) ? round(floatval($avg_row['pm10_24h']), 1) : floatval($latest['pm10']);
        
        // Philippine DENR EMB Breakpoints for PM10 (ug/m3, 24-hr avg, DAO 2000-81)
        $bp = [
            [0, 54, 0, 50],
            [55, 154, 51, 100],
            [155, 254, 101, 150],
            [255, 354, 151, 200],
            [355, 424, 201, 300],
            [425, 604, 301, 500]
        ];
        $aqi_24h = 500;
        foreach ($bp as $b) {
            list($cl, $ch, $il, $ih) = $b;
            if ($pm10_24h >= $cl && $pm10_24h <= $ch) {
                $aqi_24h = round((($ih - $il) / ($ch - $cl)) * ($pm10_24h - $cl) + $il);
                break;
            }
        }
        $latest['pm10_24h'] = $pm10_24h;
        $latest['aqi_24h'] = $aqi_24h;
        $latest['count_24h'] = $avg_row ? intval($avg_row['count_24h']) : 0;

        // ─────────────────────────────────────────────────────────────
        // PAGASA Heat Index Calculation (Philippine Atmospheric, Geophysical 
        // and Astronomical Services Administration)
        // ─────────────────────────────────────────────────────────────
        $T_c = floatval($latest['temp']);
        $RH = floatval($latest['hum']);
        $T_f = ($T_c * 9/5) + 32;
        
        if ($T_f < 80) {
            $HI_f = 0.5 * ($T_f + 61.0 + (($T_f - 68.0) * 1.2) + ($RH * 0.094));
        } else {
            $HI_f = -42.379 + (2.04901523 * $T_f) + (10.14333127 * $RH) 
                    - (0.22475541 * $T_f * $RH) - (0.00683783 * $T_f * $T_f) 
                    - (0.05481717 * $RH * $RH) + (0.00122874 * $T_f * $T_f * $RH) 
                    + (0.00085282 * $T_f * $RH * $RH) - (0.00000199 * $T_f * $T_f * $RH * $RH);
                    
            if ($RH < 13 && $T_f >= 80 && $T_f <= 112) {
                $adj = ((13 - $RH) / 4) * sqrt((17 - abs($T_f - 95)) / 17);
                $HI_f -= $adj;
            } elseif ($RH > 85 && $T_f >= 80 && $T_f <= 87) {
                $adj = (($RH - 85) / 10) * ((87 - $T_f) / 5);
                $HI_f += $adj;
            }
        }
        
        $HI_c = round(($HI_f - 32) * 5/9, 1);
        
        if ($HI_c < 27) {
            $hi_cat = "Normal";
            $hi_color = "#00CFA8";
            $hi_desc = "Minimal thermal stress.";
        } elseif ($HI_c <= 32) {
            $hi_cat = "Caution";
            $hi_color = "#4C9EEB";
            $hi_desc = "Fatigue possible with prolonged exposure.";
        } elseif ($HI_c <= 41) {
            $hi_cat = "Extreme Caution";
            $hi_color = "#F5A623";
            $hi_desc = "Heat cramps and heat exhaustion possible.";
        } elseif ($HI_c <= 51) {
            $hi_cat = "Danger";
            $hi_color = "#F05252";
            $hi_desc = "Heat exhaustion likely; heat stroke probable.";
        } else {
            $hi_cat = "Extreme Danger";
            $hi_color = "#C084FC";
            $hi_desc = "Heat stroke imminent with continued exposure.";
        }
        
        $latest['heat_index'] = $HI_c;
        $latest['heat_cat'] = $hi_cat;
        $latest['heat_color'] = $hi_color;
        $latest['heat_desc'] = $hi_desc;

        // Urban Environmental Stress Index (UESI)
        $inst_aqi = floatval($latest['aqi']);
        $max_aqi_val = max($inst_aqi, $aqi_24h);
        if ($max_aqi_val > 150 || $HI_c >= 42) {
            $uesi_level = "High Environmental Stress";
            $uesi_color = "#F05252";
            $uesi_advice = "Dual hazard: High pollution and severe thermal heat. Vulnerable individuals avoid outdoor exertion.";
        } elseif ($max_aqi_val > 100 || $HI_c >= 33) {
            $uesi_level = "Moderate Environmental Stress";
            $uesi_color = "#F5A623";
            $uesi_advice = "Elevated environmental factor detected. Stay hydrated and limit prolonged roadside exertion.";
        } else {
            $uesi_level = "Optimal Urban Conditions";
            $uesi_color = "#00CFA8";
            $uesi_advice = "Normal atmospheric dispersion and comfortable thermal conditions.";
        }
        $latest['uesi_level'] = $uesi_level;
        $latest['uesi_color'] = $uesi_color;
        $latest['uesi_advice'] = $uesi_advice;
    }
    
    echo json_encode($latest);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="theme-color" content="#00CFA8">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="AirQuality">
<link rel="manifest" href="manifest.json">
<link rel="apple-touch-icon" href="assets/icons/icon-192.png">
<link rel="icon" type="image/png" href="assets/icons/icon-192.png">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<link href="assets/css/dashboard.css" rel="stylesheet">
</head>
<body>

<div style="position: sticky; top: 0; z-index: 99990;">
<?php if($maint_mode === "ON"): ?>
<div style="background: var(--warn); color: #111; text-align: center; padding: 12px; font-family: var(--sans); font-size: 13px; font-weight: bold; letter-spacing: 0.05em; box-shadow: 0 4px 10px rgba(0,0,0,0.5);">
    ⚠️ SYSTEM UNDER MAINTENANCE: Sensor readings may be paused or inaccurate. ⚠️
</div>
<?php endif; ?>

<!-- ═══════════════════════════════════════════════════ -->
<!--  TOP BAR                                           -->
<!-- ═══════════════════════════════════════════════════ -->
<header class="topbar">
    <div class="topbar-left">
        <div class="logo-dot"></div>
        <h1>Eco Quality</h1>
    </div>
    <div class="topbar-right" style="display: flex; gap: 10px; align-items: center;">
        <button class="theme-btn" onclick="toggleTheme()" style="padding: 6px 12px; border: 1px solid var(--border); background: var(--surface); color: var(--text); border-radius: 6px; cursor: pointer; display: flex; align-items: center; gap: 6px; font-family: var(--mono); font-size: 11px;">
            <span id="theme-icon">☾</span>
            <span id="theme-label">Dark</span>
        </button>
        <button class="theme-btn" onclick="openMenu()" title="Menu" style="font-size: 16px; line-height: 1;">
            ☰
        </button>
    </div>
</header>
</div>

<!-- 🚨 RA 8749 PUBLIC HEALTH ALERT BANNER -->
<div id="health-alert-banner" class="health-alert-banner" style="display: none;">
    <div class="alert-content">
        <span class="alert-icon" id="alert-icon">⚠️</span>
        <div class="alert-text">
            <strong id="alert-title">AIR QUALITY ADVISORY (RA 8749)</strong>
            <span id="alert-body">Air pollution levels require attention.</span>
        </div>
    </div>
    <button class="alert-close" onclick="dismissAlert()">&times;</button>
</div>

<main class="main">

<!-- ═══════════════════════════════════════════════════ -->
<!--  ROW 1 — LIVE SENSOR CARDS                        -->
<!-- ═══════════════════════════════════════════════════ -->
<span class="section-label">Live Readings</span>
<div class="cards">
    <div class="card" id="aqi-card">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 2px;">
            <div class="card-label" style="margin-bottom: 0;">Air Quality Index</div>
            <span id="aqi-mode-badge" style="font-size: 8.5px; padding: 2px 6px; border-radius: 4px; background: rgba(255,255,255,0.06); font-family: var(--mono); color: var(--muted); text-transform: uppercase;">Real-Time NowCast</span>
        </div>
        <div class="card-value skeleton" id="aqi">000</div>
        <div class="card-unit skeleton" id="aqi-label">Loading Data</div>
        <div id="aqi-compliance-wrap" style="margin-top: 10px; padding-top: 8px; border-top: 1px dashed var(--border); font-size: 11px; font-family: var(--mono); display: flex; justify-content: space-between; align-items: center;">
            <span style="color: var(--muted); font-size: 10px;">24-hr RA 8749:</span>
            <span id="aqi_24h_val" style="font-weight: 600; color: var(--accent); font-size: 11px;">—</span>
        </div>
    </div>
    <div class="card" id="temp-card">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 2px;">
            <div class="card-label" style="margin-bottom: 0;">Temperature</div>
            <button type="button" class="info-btn" onclick="openTempInfo()" style="width: 14px; height: 14px; font-size: 9px; line-height: 12px; cursor: pointer;" title="Temperature Info">i</button>
        </div>
        <div class="card-value skeleton" id="temp">00.0</div>
        <div class="card-unit">°C Ambient</div>
        <div id="heat-index-wrap" style="margin-top: 10px; padding-top: 8px; border-top: 1px dashed var(--border); font-size: 11px; font-family: var(--mono); display: flex; justify-content: space-between; align-items: center;">
            <div style="display: flex; align-items: center; gap: 5px;">
                <span style="color: var(--muted); font-size: 10px;">Feels Like:</span>
                <button type="button" class="info-btn" onclick="openHeatIndexInfo()" style="width: 12px; height: 12px; font-size: 8px; line-height: 10px; cursor: pointer; display: flex; justify-content: center; align-items: center;" title="Apparent Temperature Info">i</button>
            </div>
            <span id="heat_index_val" style="font-weight: 600; color: var(--accent); font-size: 11px;">-</span>
        </div>
    </div>
    <div class="card">
        <div class="card-label">Humidity</div>
        <div class="card-value skeleton" id="hum">00.0</div>
        <div class="card-unit">%</div>
    </div>
    <div class="card" id="mq-card">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 2px;">
            <div class="card-label" style="margin-bottom: 0;">Gas Contaminants</div>
            <button type="button" class="info-btn" onclick="openMqInfo()" style="width: 14px; height: 14px; font-size: 9px; line-height: 12px; cursor: pointer;" title="MQ-135 Sensor Scope">i</button>
        </div>
        <div class="card-value skeleton" id="mq">000</div>
        <div class="card-unit" id="mq-status-label">Relative ADC Index</div>
    </div>
    <div class="card">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 2px;">
            <div class="card-label" style="margin-bottom: 0;">PM10</div>
            <button type="button" class="info-btn" onclick="openPmInfo()" style="width: 14px; height: 14px; font-size: 9px; line-height: 12px; cursor: pointer;" title="PM10 Scope">i</button>
        </div>
        <div class="card-value skeleton" id="pm">00.0</div>
        <div class="card-unit">µg/m³</div>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════ -->
<!--  ROW 2 — ENVIRONMENT INTELLIGENCE (Trend, Anomaly, Daily) -->
<!-- ═══════════════════════════════════════════════════ -->
<span class="section-label">Advanced Analytics & Diagnostics</span>
<div class="status-row"><!-- <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align: -2px; margin-right: 4px;"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line></svg> Daily Summary -->
    <div class="panel">
        <div class="panel-header">
            <span class="panel-title"><svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align: -2px; margin-right: 4px;"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line></svg> Daily Summary</span>
            <div class="header-right">
                <button class="info-btn" onclick="toggleTip(this)">i</button>
                <div class="info-tip">
                    <div class="tip-title">Daily Summary</div>
                    <div style="font-size: 11px; line-height: 1.5; color: var(--text); margin-bottom: 12px;">
                        <strong>Method:</strong> Database aggregation &amp; comparisons.<br>
                        <strong>Function:</strong> Compares today's average, minimum, and maximum readings against yesterday's.
                    </div>
                    <div style="background: rgba(255,255,255,0.03); border: 1px solid var(--border); border-radius: 6px; padding: 10px; font-size: 11px; color: var(--muted); line-height: 1.5;">
                        <strong style="color:var(--accent);">System Info:</strong><br>
                        Updates every 60 seconds via <strong>daily_summary.py</strong>.
                    </div>
                </div>
                <span class="panel-tag" id="daily-tag">loading…</span>
            </div>
        </div>
        <div class="ai-body">
            <div class="day-compare">
                <div class="day-box">
                    <div class="day-box-label">Today</div>
                    <div class="day-aqi-val" id="day_today_aqi" style="color:var(--accent)">—</div>
                    <div class="day-aqi-cat" id="day_today_cat">avg AQI</div>
                </div>
                <div class="day-box">
                    <div class="day-box-label">Yesterday</div>
                    <div class="day-aqi-val" id="day_yest_aqi" style="color:var(--muted)">—</div>
                    <div class="day-aqi-cat" id="day_yest_cat">avg AQI</div>
                </div>
            </div>
            <div class="day-stats">
                <div class="day-stat">
                    <div class="day-stat-lbl">Min AQI</div>
                    <div class="day-stat-val" id="day_min">—</div>
                </div>
                <div class="day-stat">
                    <div class="day-stat-lbl">Max AQI</div>
                    <div class="day-stat-val" id="day_max">—</div>
                </div>
                <div class="day-stat">
                    <div class="day-stat-lbl">Readings</div>
                    <div class="day-stat-val" id="day_readings">—</div>
                </div>
            </div>
            <div id="day-change-wrap"></div>
            <div class="day-summary" id="day_summary">—</div>
        </div>
    </div>

<!-- 📊 Spike Detection -->
    <div class="panel">
        <div class="panel-header">
            <span class="panel-title"><svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align: -2px; margin-right: 4px;"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"></polyline></svg> Anomaly Detection</span>
            <div class="header-right">
                <button class="info-btn" onclick="toggleTip(this)">i</button>
                <div class="info-tip">
                    <div class="tip-title">Anomaly Detection System</div>
                    <div style="font-size: 11px; line-height: 1.5; color: var(--text); margin-bottom: 12px;">
                        <strong>Method:</strong> Isolation Forest + Z-Score Explainability.<br>
                        <strong>Function:</strong> Isolation Forest isolates anomalies via random partitioning trees. Z-scores are then used to explain which sensors caused the anomaly.
                    </div>
                    <div style="background: rgba(255,255,255,0.03); border: 1px solid var(--border); border-radius: 6px; padding: 10px; font-size: 11px; color: var(--muted); line-height: 1.5;">
                        <strong style="color:var(--accent);">System Info:</strong><br>
                        Configured for 5% expected outliers (contamination). Updates every 20 seconds via <strong>detect_anomaly.py</strong>.
                    </div>
                </div>
                <span class="panel-tag" id="anomaly-tag">loading…</span>
            </div>
        </div>
        <div class="ai-body">
            <div class="anomaly-status ok" id="anomaly-status-box">
                <div class="anomaly-icon" id="anomaly-icon">🔍</div>
                <div>
                    <div class="anomaly-label" id="anomaly-label">Checking…</div>
                    <div class="anomaly-msg"   id="anomaly-msg">—</div>
                </div>
            </div>
            <div class="z-grid" id="z-grid"></div>
            
            <div class="iforest-stats" style="margin-top: 15px; border-top: 1px solid var(--border); padding-top: 10px; display: none;" id="iforest_wrap">
                <div class="anomaly-msg" style="margin-top: 15px; color: var(--muted); font-size: 11px;">Anomaly Risk Score</div>
                <div style="display: flex; align-items: center; gap: 10px;">
                    <div style="flex-grow: 1; height: 6px; background: var(--bg-tertiary); border-radius: 3px; overflow: hidden; position: relative;">
                        <div id="iforest_bar" style="height: 100%; width: 0%; background: var(--accent); transition: width 0.3s ease, background 0.3s ease;"></div>
                    </div>
                    <div id="iforest_val" style="font-family: var(--font-mono); font-size: 0.8rem; font-weight: bold;">0</div>
                </div>
            </div>
            
            <div id="stuck-wrap"></div>
            
            <!-- Pollution Source Diagnostic & Root Cause Attribution -->
            <div class="source-fingerprint-box" id="source_fingerprint_wrap" style="margin-top: 15px; border-top: 1px solid var(--border); padding-top: 10px;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px;">
                    <span style="font-size: 0.7rem; color: var(--muted); text-transform: uppercase; letter-spacing: 0.05em; font-family: var(--mono); display: flex; align-items: center; gap: 4px;">
                        Pollution Source Diagnostic
                    </span>
                    <span id="source_confidence_badge" style="font-size: 9.5px; padding: 2px 6px; border-radius: 4px; background: rgba(0, 207, 168, 0.1); color: var(--accent); font-family: var(--mono); font-weight: 600;">Match —</span>
                </div>
                <div style="background: rgba(255,255,255,0.03); border: 1px solid var(--border); border-radius: 6px; padding: 8px 10px;">
                    <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 4px;">
                        <span id="source_icon" style="font-size: 1.1rem;">🍃</span>
                        <strong id="source_title" style="font-size: 11.5px; color: var(--text); font-family: var(--sans);">Assessing…</strong>
                    </div>
                    <div id="source_reasoning" style="font-size: 10.5px; color: var(--muted); line-height: 1.4; font-family: var(--mono);">
                        Analyzing multi-sensor covariance, emission rate of change, and diurnal cycles…
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- 📈 Trend Forecast -->
    <div class="panel">
        <div class="panel-header">
            <span class="panel-title"><svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align: -2px; margin-right: 4px;"><polyline points="23 6 13.5 15.5 8.5 10.5 1 18"></polyline><polyline points="17 6 23 6 23 12"></polyline></svg> AQI Forecast</span>
            <div class="header-right">
                <button class="info-btn" onclick="toggleTip(this)">i</button>
                <div class="info-tip">
                    <div class="tip-title">AQI Forecast Model</div>
                    <div style="font-size: 11px; line-height: 1.5; color: var(--text); margin-bottom: 12px;">
                        <strong>Method:</strong> Random Forest Regressor (Ensemble ML).<br>
                        <strong>Function:</strong> Learns non-linear relationships across ALL sensor metrics (10 features) over the last 7 days to predict +1h, +2h, +3h AQI.<br>
                        <strong>Features:</strong> Temp, Hum, PM10, VOC, time-of-day, rolling averages, rates of change.
                    </div>
                    <div style="background: rgba(255,255,255,0.03); border: 1px solid var(--border); border-radius: 6px; padding: 10px; font-size: 11px; color: var(--muted); line-height: 1.5;">
                        <strong style="color:var(--accent);">System Info:</strong><br>
                        Updates every 30 seconds via <strong>rf_predictor.py</strong>.
                    </div>
                </div>
                <span class="panel-tag" id="trend-tag">loading…</span>
            </div>
        </div>
        <div class="ai-body">
            <div class="trend-grid">
                <div class="trend-box">
                    <div class="trend-hour">+1 hr</div>
                    <div class="trend-val" id="trend_1h" style="color:var(--accent)">—</div>
                    <div class="trend-cat" id="trend_cat1">—</div>
                </div>
                <div class="trend-box">
                    <div class="trend-hour">+2 hr</div>
                    <div class="trend-val" id="trend_2h" style="color:var(--warn)">—</div>
                    <div class="trend-cat" id="trend_cat2">—</div>
                </div>
                <div class="trend-box">
                    <div class="trend-hour">+3 hr</div>
                    <div class="trend-val" id="trend_3h" style="color:var(--soft)">—</div>
                    <div class="trend-cat" id="trend_cat3">—</div>
                </div>
            </div>
            <div id="trend-badge-wrap"></div>
            <div class="trend-msg" id="trend_msg">—</div>
            
            <div class="feature-importance-wrapper" style="margin-top: 15px; border-top: 1px solid var(--border); padding-top: 10px; display: none;" id="feat_wrap">
                <div style="font-size: 0.75rem; color: var(--muted); margin-bottom: 8px;">Key Prediction Drivers</div>
                <div id="feat_list" style="display: flex; gap: 8px; flex-wrap: wrap;"></div>
            </div>
            
            <!-- Model Validation Benchmark (Academic Defense Component) -->
            <div class="model-benchmark-box" id="model-benchmark-box" style="margin-top: 15px; border-top: 1px solid var(--border); padding-top: 10px;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px;">
                    <span style="font-size: 0.7rem; color: var(--muted); text-transform: uppercase; letter-spacing: 0.05em; font-family: var(--mono);">Model Evaluation (7-Day Benchmark)</span>
                    <span id="bm-r2" style="font-size: 0.75rem; color: var(--accent); font-family: var(--mono); font-weight: bold;">R²: —</span>
                </div>
                <div style="overflow-x: auto;">
                    <table class="benchmark-mini-table">
                        <thead>
                            <tr>
                                <th>Architecture</th>
                                <th>MAE</th>
                                <th>R² Score</th>
                                <th>Metric</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr class="rf-row">
                                <td><strong>Random Forest (100 Trees)</strong></td>
                                <td id="bm-rf-mae">—</td>
                                <td id="bm-rf-r2">—</td>
                                <td><span class="bm-badge active">Selected</span></td>
                            </tr>
                            <tr class="lr-row">
                                <td>Linear Regression (Baseline)</td>
                                <td id="bm-lr-mae">—</td>
                                <td id="bm-lr-r2">Baseline</td>
                                <td><span class="bm-badge baseline">Reference</span></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div id="bm-summary" style="font-size: 10px; color: var(--muted); font-family: var(--mono); margin-top: 6px;">
                    Ensemble ML achieves <span id="bm-imp" style="color: var(--accent); font-weight: bold;">—%</span> error reduction over baseline.
                </div>
            </div>
        </div>
    </div>

    </div><!-- /status-row -->

<!-- ═══════════════════════════════════════════════════ -->
<!--  ROW 3 — Sensor History Chart + Recent Readings Table -->
<!-- ═══════════════════════════════════════════════════ -->
<span class="section-label">Reading History & Telemetry</span>
<div class="content-row">

    <!-- Sensor History Chart -->
    <div class="panel">
        <div class="panel-header">
            <span class="panel-title">Sensor History</span>
            <span class="panel-tag" id="row-count">—</span>
        </div>
        <div class="chart-wrap"><canvas id="chart"></canvas></div>
    </div>

</div><!-- /content-row -->

    <!-- Dashboard Footer & Academic/Research Utility -->
    <footer class="dashboard-footer">
        <div class="footer-left" style="width: 100%; text-align: center;">
            <strong>Eco Quality</strong><br>
            <span>Air Quality Monitoring System · Borongan City, Eastern Samar</span>
        </div>
    </footer>

</main>

<!-- Side Menu -->
<div id="menu-backdrop" class="menu-backdrop" onclick="closeMenu()"></div>
<div id="side-menu" class="side-menu">
    <div class="menu-header">
        <h3>Menu</h3>
        <button onclick="closeMenu()" class="btn-close-menu">&times;</button>
    </div>
    <div class="menu-content">
        <a href="downloads/eco_quality.apk" class="menu-link apk-dl-btn" download onclick="closeMenu();">
            <svg class="icon" style="width: 16px; height: 16px; margin-right: 8px; fill: currentColor; vertical-align: text-bottom;" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 576 512"><path d="M420.2 181.8c2.1-1.7 4.9-2.5 7.6-2.5 6.6 0 12 5.4 12 12 0 3.2-1.3 6.3-3.5 8.5l-50 49.3c58.2 30.5 98.4 89.2 101.4 157.9H88.3c3-68.7 43.3-127.4 101.4-157.9l-50-49.3c-2.2-2.2-3.5-5.3-3.5-8.5 0-6.6 5.4-12 12-12 2.7 0 5.5 .8 7.6 2.5l52 42.4c31.1-14.7 65.3-22.7 101.2-22.7s70 8 101.2 22.7l52-42.4zM224 288c-17.7 0-32 14.3-32 32s14.3 32 32 32 32-14.3 32-32-14.3-32-32-32zm128 0c-17.7 0-32 14.3-32 32s14.3 32 32 32 32-14.3 32-32-14.3-32-32-32z"/></svg> Android
        </a>
        <button onclick="showIosInstructions(); closeMenu();" class="menu-link apk-dl-btn">
            <svg class="icon" style="width: 14px; height: 14px; margin-right: 9px; margin-left: 1px; fill: currentColor; vertical-align: text-bottom;" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 384 512"><path d="M318.7 268.7c-.2-36.7 16.4-64.4 50-84.8-18.8-26.9-47.2-41.7-84.7-44.6-35.5-2.8-74.3 20.7-88.5 20.7-15 0-49.4-19.7-76.4-19.7C63.3 141.2 24 184.8 8 273.5q-1.9 10.9-1.9 22.5c0 71.5 26 133.9 66.5 190.7 21.6 30.2 46.2 56 79.5 56 31 0 46-19.1 82.2-19.1 36.3 0 49.3 19 82.2 19 33.7 0 57.3-25.2 79.5-56 22-29.4 34.6-60.5 40.5-66.5-1.3-.9-58.8-22.3-58.8-90.4zM245.9 83c20.3-25.7 33-61.9 29-99-31.5 1.5-68.9 21.4-89.8 46.9-17.7 21.6-32.2 58.7-27.5 94.6 34.6 2.6 67.9-16.7 88.3-42.5z"/></svg> iOS
        </button>
        <hr class="menu-divider">
        <button onclick="nativeShare(); closeMenu();" class="menu-link">
            <span class="icon">🔗</span> Share Live AQI
        </button>
        <a href="export.php" class="menu-link" onclick="closeMenu();">
            <span class="icon">↓</span> Export Logs (CSV)
        </a>
        <hr class="menu-divider">
        <button onclick="openFeedback(); closeMenu();" class="menu-link">
            <span class="icon">💬</span> Send Feedback
        </button>
    </div>
</div>
<!-- Glassmorphism Modal for Tooltips -->
<div id="glass-modal" class="glass-modal" onclick="closeModal(event)">
    <div class="glass-modal-content" onclick="event.stopPropagation()">
        <button class="close-modal-btn" onclick="closeModal(event)">&times;</button>
        <div id="glass-modal-body"></div>
    </div>
</div>

<!-- Glassmorphism Modal for Feedback -->
<div id="feedback-modal" class="glass-modal" onclick="closeFeedback(event)">
    <div class="glass-modal-content" onclick="event.stopPropagation()">
        <button class="close-modal-btn" onclick="closeFeedback(event)">&times;</button>
        <div class="tip-title">System Feedback</div>
        <p style="font-size: 11px; color: var(--muted); font-family: var(--font-mono); margin-bottom: 16px; line-height: 1.4;">
            Report bugs, concerns, or inquiries. Messages are sent directly to saimonrando9104@gmail.com.
        </p>
        <form action="https://formsubmit.co/saimonrando9104@gmail.com" method="POST" class="feedback-form">
            <input type="hidden" name="_captcha" value="false">
            <input type="hidden" name="_next" value="http://168.138.165.221/ai_quality/dashboard.php">
            <input type="hidden" name="_subject" value="New Feedback from Air Quality Dashboard!">
            <input type="text" name="name" placeholder="Your Name (Optional)" class="fb-input">
            <input type="email" name="email" placeholder="Your Email Address" required class="fb-input">
            <textarea name="message" placeholder="Describe your concern, bug, or inquiry..." required class="fb-input" rows="4"></textarea>
            <button type="submit" class="fb-submit">Send Message</button>
        </form>
    </div>
</div>

<!-- Custom PWA Install Prompt -->
<div id="install-prompt" class="install-prompt">
    <div class="install-content">
        <div class="install-text">
            <strong>📲 Install Eco Quality</strong>
            <span>Add to home screen for quick access</span>
        </div>
        <div class="install-actions">
            <button id="btn-install" class="btn-install">Install</button>
            <button id="btn-install-close" class="btn-close-install">&times;</button>
        </div>
    </div>
</div>

<script src="assets/js/dashboard.js?v=20" defer></script>
<script>
if ('serviceWorker' in navigator) {
    window.addEventListener('load', () => {
        navigator.serviceWorker.register('./sw.js?update=15')
            .then(reg => {
                console.log('SW Registered', reg.scope);
                reg.update(); // Force update check
            })
            .catch(err => console.log('SW Failed', err));
    });
}
</script>
<!-- Smart App Banner -->
<div id="smart-banner" class="smart-banner">
    <div class="sb-text">
        <div class="sb-title">Eco Quality</div>
        <div id="sb-sub" class="sb-sub">Download the mobile app</div>
    </div>
    <a id="sb-btn" href="#" class="sb-btn">Install</a>
    <button class="sb-close" onclick="document.getElementById('smart-banner').classList.remove('show')">&times;</button>
</div>

</body>
</html>
