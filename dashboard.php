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

        // Urban Environmental Stress Index (UESI) - anchored to sustained 24h AQI
        $max_aqi_val = $aqi_24h;
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
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<meta name="theme-color" content="#00CFA8">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="Eco Quality">
<link rel="manifest" href="manifest.json">
<link rel="apple-touch-icon" href="assets/icons/icon-192.png">
<link rel="icon" type="image/png" href="assets/icons/icon-192.png">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=JetBrains+Mono:wght@400;500;600;700&display=swap" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<link href="assets/css/dashboard.css?v=35" rel="stylesheet">
<script>
    // System Validation: Early Device Theme Detection (Anti-FOUC)
    (function() {
        try {
            var saved = localStorage.getItem('aq-theme');
            var prefersLight = window.matchMedia && window.matchMedia('(prefers-color-scheme: light)').matches;
            var isLight = (saved === 'light' || saved === 'dark') ? (saved === 'light') : prefersLight;
            if (isLight) {
                document.documentElement.classList.add('light');
                document.addEventListener('DOMContentLoaded', function() {
                    if (document.body) document.body.classList.add('light');
                });
            }
        } catch (e) {}
    })();
</script>
</head>
<body>

<!-- ═══════════════════════════════════════════════════════════════════════════ -->
<!--  STICKY TOP BAR                                                            -->
<!-- ═══════════════════════════════════════════════════════════════════════════ -->
<header class="topbar">
    <div class="topbar-left">
        <a href="dashboard.php" class="brand-container" title="Eastern Samar State University - Eco Quality">
            <div class="pulse-dot logo-dot" title="Sensor node connection active"></div>
            <div class="brand-text">
                <span class="brand-title">Eco Quality</span>
                <span class="brand-subtitle">ESSU Urban Air Station</span>
            </div>
        </a>
    </div>

    <div class="topbar-center">
        <!-- Dual-Mode Segmented View Switch (Progressive Disclosure) -->
        <nav class="segmented-control" role="tablist" aria-label="Dashboard View Modes">
            <button class="seg-btn active" id="btn-citizen" role="tab" aria-selected="true" aria-controls="view-citizen" onclick="switchViewMode('citizen')">
                <span class="seg-icon">👤</span>
                <span class="seg-text">Citizen Overview</span>
            </button>
            <button class="seg-btn" id="btn-technical" role="tab" aria-selected="false" aria-controls="view-technical" onclick="switchViewMode('technical')">
                <span class="seg-icon">🔬</span>
                <span class="seg-text">Technical Diagnostics</span>
            </button>
        </nav>
    </div>

    <div class="topbar-right">
        <button class="btn-icon-action" onclick="toggleTheme()" title="Toggle Dark/Light Theme" aria-label="Toggle Theme">
            <span id="theme-icon">☾</span>
            <span id="theme-label" style="display: none;">Dark</span>
        </button>
        <button class="btn-icon-action" onclick="openMenu()" title="System Navigation Menu" aria-label="Open Navigation Menu">
            ☰
        </button>
    </div>
</header>

<?php if($maint_mode === "ON"): ?>
<div style="background: var(--warn); color: #111; text-align: center; padding: 10px 16px; font-size: 13px; font-weight: 700; letter-spacing: 0.04em;">
    ⚠️ SYSTEM UNDER MAINTENANCE: Telemetry readings may be undergoing calibration.
</div>
<?php endif; ?>

<!-- 🚨 CONDITIONAL PUBLIC HEALTH ALERT BANNER -->
<div id="health-alert-banner" class="health-alert-banner" style="display: none;" role="alert">
    <div class="alert-content">
        <span class="alert-icon" id="alert-icon">⚠️</span>
        <div class="alert-text">
            <strong id="alert-title">AIR QUALITY ADVISORY</strong>
            <span id="alert-body">Air pollution levels require attention.</span>
        </div>
    </div>
    <button class="alert-close" onclick="dismissAlert()" aria-label="Dismiss alert">&times;</button>
</div>

<!-- ═══════════════════════════════════════════════════════════════════════════ -->
<!--  MAIN VIEWPORT                                                             -->
<!-- ═══════════════════════════════════════════════════════════════════════════ -->
<main class="main-viewport">

    <!-- ───────────────────────────────────────────────────────────────────── -->
    <!--  MODE 1: CITIZEN OVERVIEW (DEFAULT)                                    -->
    <!-- ───────────────────────────────────────────────────────────────────── -->
    <section id="view-citizen" class="view-section active" role="tabpanel" aria-labelledby="btn-citizen">
        
        <!-- Hero Section (2-Column Desktop Grid, Stacked Mobile) -->
        <div class="hero-grid">
            <!-- Hero A: Primary Air Quality Gauge -->
            <div class="card hero-card hero-aqi" id="aqi-card">
                <div class="card-header">
                    <div class="card-title-group">
                        <span class="card-eyebrow">
                            <svg class="icon-svg" xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="color: var(--accent);"><path d="M9.59 4.59A2 2 0 1 1 11 8H2m10.59 11.41A2 2 0 1 0 14 16H2m15.73-8.27A2.5 2.5 0 1 1 19.5 12H2"/></svg>
                            Air Quality Index
                        </span>
                        <span class="badge-pill" id="aqi-mode-badge">NOWCAST</span>
                    </div>
                </div>
                
                <div class="aqi-score-container">
                    <div class="aqi-value skeleton" id="aqi">000</div>
                    <div class="aqi-status-pill skeleton" id="aqi-label">Loading Data</div>
                </div>

                <div class="aqi-guidance-box" id="aqi-guidance-box">
                    <div class="guidance-icon">🛡️</div>
                    <div class="guidance-content">
                        <span class="guidance-title">Health Action Guidance</span>
                        <p class="guidance-text" id="aqi_health_guidance">Analyzing atmospheric conditions...</p>
                    </div>
                </div>

                <div class="card-footer-meta" id="aqi-compliance-wrap">
                    <div class="meta-item">
                        <span class="meta-label">24-Hour Average:</span>
                        <span class="meta-value" id="aqi_24h_val">—</span>
                    </div>
                    <div class="meta-item">
                        <span class="meta-label">Standard:</span>
                        <span class="meta-value strong">DENR DAO 2000-81</span>
                    </div>
                </div>
            </div>

            <!-- Hero B: 3-Hour Predictive Outlook -->
            <div class="card hero-card hero-forecast" id="forecast-hero-card">
                <div class="card-header">
                    <div class="card-title-group">
                        <span class="card-eyebrow">
                            <svg class="icon-svg" xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="color: var(--warn);"><polyline points="23 6 13.5 15.5 8.5 10.5 1 18"></polyline><polyline points="17 6 23 6 23 12"></polyline></svg>
                            3-Hour Predictive Outlook
                        </span>
                        <span class="badge-pill rf-badge" id="trend-tag">Random Forest ML</span>
                    </div>
                </div>

                <div class="forecast-step-row">
                    <div class="forecast-step-card">
                        <div class="step-hour">+1 Hour</div>
                        <div class="step-aqi skeleton" id="trend_1h">—</div>
                        <div class="step-cat" id="trend_cat1">—</div>
                    </div>
                    <div class="forecast-step-card">
                        <div class="step-hour">+2 Hours</div>
                        <div class="step-aqi skeleton" id="trend_2h">—</div>
                        <div class="step-cat" id="trend_cat2">—</div>
                    </div>
                    <div class="forecast-step-card">
                        <div class="step-hour">+3 Hours</div>
                        <div class="step-aqi skeleton" id="trend_3h">—</div>
                        <div class="step-cat" id="trend_cat3">—</div>
                    </div>
                </div>

                <div class="forecast-summary-box">
                    <div class="forecast-trend-icon">📈</div>
                    <div class="forecast-summary-content">
                        <span class="summary-label">Random Forest Trend</span>
                        <p class="summary-text" id="trend_msg">Stable air quality predicted over the next 3 hours.</p>
                    </div>
                </div>

                <div class="forecast-card-footer">
                    <button type="button" class="btn-driver-link" onclick="switchViewMode('technical', 'ml')">
                        <span>Quick-access Key Drivers & Algorithm Evaluation</span>
                        <span class="arrow">→</span>
                    </button>
                </div>
            </div>
        </div>

        <!-- Clean Telemetry Row (4 Cards) -->
        <div class="section-heading">
            <h2 class="section-title">Environmental Telemetry</h2>
            <span class="section-subtitle">Real-time localized ambient microclimate metrics</span>
        </div>

        <div class="telemetry-grid">
            <!-- Temperature Card -->
            <div class="card telemetry-card" id="temp-card">
                <div class="card-header">
                    <div class="card-title-group">
                        <span class="card-eyebrow">
                            <svg class="icon-svg" xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="color: var(--danger);"><path d="M14 14.76V3.5a2.5 2.5 0 0 0-5 0v11.26a4.5 4.5 0 1 0 5 0z"/></svg>
                            Temperature
                        </span>
                        <button type="button" class="btn-info-circle" onclick="openTempInfo()" title="Temperature Sensor Info" aria-label="Temperature info">i</button>
                    </div>
                </div>
                <div class="telemetry-body">
                    <div class="telemetry-reading">
                        <span class="telemetry-num skeleton" id="temp">00.0</span>
                        <span class="telemetry-unit">°C</span>
                    </div>
                    <div class="telemetry-sub">Ambient Temperature</div>
                </div>
                <div class="telemetry-footer" id="heat-index-wrap">
                    <div class="meta-row">
                        <span class="meta-label">Feels Like:</span>
                        <span class="meta-value" id="heat_index_val">—</span>
                        <button type="button" class="btn-info-circle-sm" onclick="openHeatIndexInfo()" title="Apparent Temperature Info" aria-label="Heat index info">i</button>
                    </div>
                </div>
            </div>

            <!-- Humidity Card -->
            <div class="card telemetry-card" id="hum-card">
                <div class="card-header">
                    <div class="card-title-group">
                        <span class="card-eyebrow">
                            <svg class="icon-svg" xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="color: var(--cyan);"><path d="M12 2.69l5.66 5.66a8 8 0 1 1-11.31 0z"/></svg>
                            Humidity
                        </span>
                        <span class="badge-pill sensor-tag">DHT22</span>
                    </div>
                </div>
                <div class="telemetry-body">
                    <div class="telemetry-reading">
                        <span class="telemetry-num skeleton" id="hum">00.0</span>
                        <span class="telemetry-unit">% RH</span>
                    </div>
                    <div class="telemetry-sub">Relative Humidity</div>
                </div>
                <div class="telemetry-footer">
                    <div class="meta-row">
                        <span class="meta-label">Comfort:</span>
                        <span class="meta-value strong" id="hum-comfort-label">Standard Range</span>
                    </div>
                </div>
            </div>

            <!-- PM10 Particulate Card -->
            <div class="card telemetry-card" id="pm10-card">
                <div class="card-header">
                    <div class="card-title-group">
                        <span class="card-eyebrow">
                            <svg class="icon-svg" xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="color: var(--accent);"><polygon points="12 2 2 7 12 12 22 7 12 2"></polygon><polyline points="2 17 12 22 22 17"></polyline><polyline points="2 12 12 17 22 12"></polyline></svg>
                            Particulate PM10
                        </span>
                        <button type="button" class="btn-info-circle" onclick="openPmInfo()" title="PM10 Scope Info" aria-label="PM10 info">i</button>
                    </div>
                </div>
                <div class="telemetry-body">
                    <div class="telemetry-reading">
                        <span class="telemetry-num skeleton" id="pm">00.0</span>
                        <span class="telemetry-unit">µg/m³</span>
                    </div>
                    <div class="telemetry-sub">Laser Scattering (PMS5003)</div>
                </div>
                <div class="telemetry-footer">
                    <div class="meta-row">
                        <span class="meta-label">Scope:</span>
                        <span class="meta-value strong">Inhalable Dust (≤10µm)</span>
                    </div>
                </div>
            </div>

            <!-- Gas Contaminants Card -->
            <div class="card telemetry-card" id="mq-card">
                <div class="card-header">
                    <div class="card-title-group">
                        <span class="card-eyebrow">
                            <svg class="icon-svg" xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="color: var(--warn);"><path d="M18 10h-1.26A8 8 0 1 0 9 20h9a5 5 0 0 0 0-10z"/></svg>
                            Gas Contaminants
                        </span>
                        <button type="button" class="btn-info-circle" onclick="openMqInfo()" title="MQ-135 Gas Scope Info" aria-label="MQ-135 info">i</button>
                    </div>
                </div>
                <div class="telemetry-body">
                    <div class="telemetry-reading">
                        <span class="telemetry-num skeleton" id="mq">000</span>
                        <span class="telemetry-unit">ADC Index</span>
                    </div>
                    <div class="telemetry-sub" id="mq-status-label">Relative Baseline</div>
                </div>
                <div class="telemetry-footer">
                    <div class="meta-row">
                        <span class="meta-label">Target:</span>
                        <span class="meta-value strong">CO, NH3, Smoke</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Progressive Disclosure Callout -->
        <div class="disclosure-callout-card" onclick="switchViewMode('technical')">
            <div class="disclosure-left">
                <span class="disclosure-icon">🔬</span>
                <div class="disclosure-texts">
                    <strong>Looking for In-Depth Technical & AI Diagnostics?</strong>
                    <span>Inspect model validation benchmarks ($R^2=0.952$), Isolation Forest anomaly Z-scores, and interactive sensor history timelines.</span>
                </div>
            </div>
            <button type="button" class="btn-disclosure-action">
                <span>Explore Diagnostics</span>
                <span class="arrow">→</span>
            </button>
        </div>
    </section>

    <!-- ───────────────────────────────────────────────────────────────────── -->
    <!--  MODE 2: TECHNICAL & AI DIAGNOSTICS VIEWPORT (EXPANDABLE/TABBED)       -->
    <!-- ───────────────────────────────────────────────────────────────────── -->
    <section id="view-technical" class="view-section" style="display: none;" role="tabpanel" aria-labelledby="btn-technical">
        <!-- Progressive Disclosure Sub-Tabs -->
        <div class="sub-tab-bar" role="tablist" aria-label="Technical Diagnostics Subsections">
            <button class="sub-tab-btn active" id="subtab-daily-chart" role="tab" aria-selected="true" onclick="switchTechTab('daily-chart')">
                <span class="tab-icon">📅</span>
                <span>Daily Trends & Interactive History</span>
            </button>
            <button class="sub-tab-btn" id="subtab-ml-anomaly" role="tab" aria-selected="false" onclick="switchTechTab('ml-anomaly')">
                <span class="tab-icon">🧠</span>
                <span>Machine Learning & Anomaly Diagnostics</span>
            </button>
        </div>

        <!-- SUB-TAB 1: Daily Trends & History Chart -->
        <div id="tech-pane-daily-chart" class="tech-sub-pane active">
            <!-- Daily Summary Panel -->
            <div class="panel diag-panel" id="daily-panel">
                <div class="panel-header">
                    <div class="panel-title-group">
                        <span class="panel-title">
                            <svg class="icon-svg" xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line></svg>
                            Daily Summary & Comparisons
                        </span>
                        <span class="badge-pill" id="daily-tag">loading…</span>
                    </div>
                    <div class="panel-actions">
                        <a href="export.php" class="btn-subtle-export" title="Export CSV Data Logs">
                            <span class="icon">↓</span> CSV Export
                        </a>
                        <button type="button" class="btn-info-circle" onclick="toggleTip(this)" title="Methodology info" aria-label="Daily summary info">i</button>
                        <div class="info-tip">
                            <div class="tip-title">Daily Summary Methodology</div>
                            <div style="font-size: 11px; line-height: 1.5; color: var(--text); margin-bottom: 12px;">
                                <strong>Method:</strong> Database aggregation &amp; comparisons.<br>
                                <strong>Function:</strong> Compares today's average, minimum, and maximum readings against yesterday's.
                            </div>
                            <div style="background: rgba(255,255,255,0.03); border: 1px solid var(--border); border-radius: 6px; padding: 10px; font-size: 11px; color: var(--muted); line-height: 1.5;">
                                <strong style="color:var(--accent);">Update Cadence:</strong> Aggregated and refreshed automatically every 60 seconds.
                            </div>
                        </div>
                    </div>
                </div>

                <div class="panel-body">
                    <div class="daily-metrics-grid">
                        <div class="daily-compare-card">
                            <div class="compare-col">
                                <span class="compare-lbl">Today's Avg</span>
                                <div class="compare-val" id="day_today_aqi">—</div>
                                <span class="compare-cat" id="day_today_cat">AQI</span>
                            </div>
                            <div class="compare-divider"></div>
                            <div class="compare-col">
                                <span class="compare-lbl">Yesterday's Avg</span>
                                <div class="compare-val" id="day_yest_aqi">—</div>
                                <span class="compare-cat" id="day_yest_cat">AQI</span>
                            </div>
                        </div>
                        
                        <div class="daily-pills-row">
                            <div class="metric-pill">
                                <span class="pill-lbl">Min AQI</span>
                                <span class="pill-val" id="day_min">—</span>
                            </div>
                            <div class="metric-pill">
                                <span class="pill-lbl">Max AQI</span>
                                <span class="pill-val" id="day_max">—</span>
                            </div>
                            <div class="metric-pill">
                                <span class="pill-lbl">Readings</span>
                                <span class="pill-val" id="day_readings">—</span>
                            </div>
                        </div>
                    </div>

                    <div id="day-change-wrap" class="daily-change-banner"></div>
                    <div class="daily-narrative" id="day_summary">—</div>
                </div>
            </div>

            <!-- Interactive Sensor History Chart -->
            <div class="panel diag-panel" id="history-panel">
                <div class="panel-header">
                    <div class="panel-title-group">
                        <span class="panel-title">
                            <svg class="icon-svg" xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 3v18h18"/><path d="m19 9-5 5-4-4-3 3"/></svg>
                            Interactive Sensor History Timeline
                        </span>
                        <span class="badge-pill" id="row-count">—</span>
                    </div>
                </div>

                <!-- Multi-sensor Series Toggle Buttons -->
                <div class="chart-controls-strip">
                    <span class="filter-label">Toggle Series:</span>
                    <div class="filter-pills" id="chart-toggles">
                        <button type="button" class="chart-pill active" data-ds="0" onclick="toggleChartSeries(0, this)">
                            <span class="color-dot" style="background:#00CFA8;"></span> PM10
                        </button>
                        <button type="button" class="chart-pill active" data-ds="1" onclick="toggleChartSeries(1, this)">
                            <span class="color-dot" style="background:#F5A623;"></span> AQI
                        </button>
                        <button type="button" class="chart-pill active" data-ds="2" onclick="toggleChartSeries(2, this)">
                            <span class="color-dot" style="background:#F05252;"></span> Temp
                        </button>
                        <button type="button" class="chart-pill active" data-ds="3" onclick="toggleChartSeries(3, this)">
                            <span class="color-dot" style="background:#4C9EEB;"></span> Hum
                        </button>
                        <button type="button" class="chart-pill active" data-ds="4" onclick="toggleChartSeries(4, this)">
                            <span class="color-dot" style="background:#8A93B8;"></span> MQ-135
                        </button>
                    </div>
                </div>

                <div class="chart-canvas-wrap">
                    <canvas id="chart"></canvas>
                </div>
            </div>
        </div>

        <!-- SUB-TAB 2: Machine Learning & Anomaly Diagnostics -->
        <div id="tech-pane-ml-anomaly" class="tech-sub-pane" style="display: none;">
            <div class="diagnostics-split-grid">
                <!-- Left Column: Prediction Drivers & Model Evaluation Benchmark -->
                <div class="diag-column">
                    <!-- Predictive Drivers (Feature Importance) -->
                    <div class="panel diag-panel" id="drivers-panel">
                        <div class="panel-header">
                            <div class="panel-title-group">
                                <span class="panel-title">
                                    <svg class="icon-svg" xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" x2="18" y1="20" y2="10"/><line x1="12" x2="12" y1="20" y2="4"/><line x1="6" x2="6" y1="20" y2="14"/></svg>
                                    Key Prediction Drivers (Random Forest)
                                </span>
                            </div>
                            <button type="button" class="btn-info-circle" onclick="toggleTip(this)" title="Feature Importance Info" aria-label="Feature importance info">i</button>
                            <div class="info-tip">
                                <div class="tip-title">Random Forest Feature Importance</div>
                                <div style="font-size: 11px; line-height: 1.5; color: var(--text); margin-bottom: 12px;">
                                    Gini-importance (mean decrease in impurity) computed across all 100 decision trees to determine which environmental parameters exert the strongest influence on predicted AQI.
                                </div>
                            </div>
                        </div>
                        <div class="panel-body">
                            <div id="feat_wrap" class="feature-importance-container">
                                <div id="feat_list" class="feature-bars-grid"></div>
                            </div>
                        </div>
                    </div>

                    <!-- Model Evaluation Benchmark (Academic Defense Deliverable) -->
                    <div class="panel diag-panel" id="benchmark-panel">
                        <div class="panel-header">
                            <div class="panel-title-group">
                                <span class="panel-title">
                                    <svg class="icon-svg" xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="m9 12 2 2 4-4"/></svg>
                                    Model Evaluation Benchmark
                                </span>
                                <span class="badge-pill rf-badge" id="bm-r2">R² = 0.952</span>
                            </div>
                        </div>
                        <div class="panel-body">
                            <div class="benchmark-table-wrap">
                                <table class="benchmark-table">
                                    <thead>
                                        <tr>
                                            <th>Architecture</th>
                                            <th>MAE</th>
                                            <th>R² Score</th>
                                            <th>Status</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr class="highlight-row">
                                            <td>
                                                <div class="arch-cell">
                                                    <strong>Random Forest Regressor</strong>
                                                    <span class="arch-sub">100 Estimators · Non-linear</span>
                                                </div>
                                            </td>
                                            <td id="bm-rf-mae" class="tabular-stat">±2.74 AQI</td>
                                            <td id="bm-rf-r2" class="tabular-stat">0.952 (95.2% fit)</td>
                                            <td><span class="eval-badge active">Deployed</span></td>
                                        </tr>
                                        <tr>
                                            <td>
                                                <div class="arch-cell">
                                                    <span>Linear Regression Baseline</span>
                                                    <span class="arch-sub">Ordinary Least Squares</span>
                                                </div>
                                            </td>
                                            <td id="bm-lr-mae" class="tabular-stat">±14.55 AQI</td>
                                            <td id="bm-lr-r2" class="tabular-stat">Baseline</td>
                                            <td><span class="eval-badge baseline">Reference</span></td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                            <div class="benchmark-improvement-callout">
                                <div class="improvement-badge" id="bm-imp">81.2%</div>
                                <div class="improvement-text">
                                    Ensemble Random Forest reduces prediction error by <strong>81.2%</strong> compared to linear regression baseline, capturing complex diurnal and multi-gas interactions.
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Right Column: Anomaly Detection & Pollution Source Fingerprint -->
                <div class="diag-column">
                    <!-- Anomaly Detection Center -->
                    <div class="panel diag-panel" id="anomaly-panel">
                        <div class="panel-header">
                            <div class="panel-title-group">
                                <span class="panel-title">
                                    <svg class="icon-svg" xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                                    Anomaly Detection Center
                                </span>
                                <span class="badge-pill" id="anomaly-tag">loading…</span>
                            </div>
                            <button type="button" class="btn-info-circle" onclick="toggleTip(this)" title="Anomaly Info" aria-label="Anomaly info">i</button>
                            <div class="info-tip">
                                <div class="tip-title">Hybrid Isolation Forest & Z-Score</div>
                                <div style="font-size: 11px; line-height: 1.5; color: var(--text); margin-bottom: 12px;">
                                    <strong>Isolation Forest:</strong> Isolates multivariate anomalies via tree depth.<br>
                                    <strong>Z-Score Explainability:</strong> Attribute the root-cause outlier sensor when threshold deviations exceed standard bounds.
                                </div>
                            </div>
                        </div>
                        <div class="panel-body">
                            <div class="anomaly-status ok" id="anomaly-status-box">
                                <div class="anomaly-icon" id="anomaly-icon">✓</div>
                                <div class="anomaly-details">
                                    <div class="anomaly-label" id="anomaly-label">Checking System…</div>
                                    <div class="anomaly-msg" id="anomaly-msg">Evaluating continuous telemetry parameters</div>
                                </div>
                            </div>

                            <!-- Isolation Forest Risk Score Bar (0–100) -->
                            <div class="iforest-container" id="iforest_wrap">
                                <div class="risk-bar-header">
                                    <span class="risk-label">Isolation Forest Anomaly Risk Score</span>
                                    <span class="risk-val" id="iforest_val">0</span>
                                </div>
                                <div class="risk-bar-track">
                                    <div class="risk-bar-fill" id="iforest_bar" style="width: 0%;"></div>
                                </div>
                            </div>

                            <!-- Z-Score Explainability Grid -->
                            <div class="zscore-section">
                                <span class="zscore-title">Multi-Sensor Z-Score Explainability</span>
                                <div class="z-grid" id="z-grid"></div>
                            </div>

                            <div id="stuck-wrap"></div>
                        </div>
                    </div>

                    <!-- Pollution Source Diagnostic -->
                    <div class="panel diag-panel" id="source_fingerprint_wrap">
                        <div class="panel-header">
                            <div class="panel-title-group">
                                <span class="panel-title">
                                    <svg class="icon-svg" xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2v20M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
                                    Pollution Source Diagnostic
                                </span>
                                <span class="badge-pill" id="source_confidence_badge">Match —</span>
                            </div>
                        </div>
                        <div class="panel-body">
                            <div class="source-card">
                                <div class="source-head">
                                    <span class="source-icon" id="source_icon">🍃</span>
                                    <strong class="source-title" id="source_title">Assessing Covariance…</strong>
                                </div>
                                <p class="source-reasoning" id="source_reasoning">
                                    Analyzing multi-sensor covariance, emission rate of change, and diurnal cycles…
                                </p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

</main>

<!-- ═══════════════════════════════════════════════════════════════════════════ -->
<!--  CITIZEN FOOTER (RA 8749 / DENR DAO 2000-81 MANDATE)                        -->
<!-- ═══════════════════════════════════════════════════════════════════════════ -->
<footer class="citizen-footer">
    <div class="citizen-footer-content">
        <div class="footer-primary-text">
            <span class="footer-badge">ESSU CAPSTONE RESEARCH // RA 8749 MANDATE</span>
            <p class="footer-description">
                Atmospheric particulate telemetry (PM10) and relative gas contamination indices are monitored via publicly-deployed IoT telemetry sensing nodes. Real-time data is served for public health awareness and ambient environmental assessment under the Philippine Clean Air Act (RA 8749) and DENR DAO 2000-81.
            </p>
        </div>
        <div class="footer-meta-strip">
            <div class="meta-left">
                <span>STATION RUNTIME: <strong class="highlight-accent">24/7 CONTINUOUS IoT</strong></span>
                <span class="divider">•</span>
                <span>DOCUMENT REF: <strong>EQ-UIUX-2026</strong></span>
            </div>
            <div class="meta-right">
                <span>&copy; <?= date('Y') ?> Eastern Samar State University &bull; Eco Quality Project &bull; <a href="admin.php" class="subtle-console-link" title="Administrative Console">Admin Portal</a></span>
            </div>
        </div>
    </div>
</footer>

<!-- ═══════════════════════════════════════════════════════════════════════════ -->
<!--  DRAWER MENU, MODALS & DIALOGS                                             -->
<!-- ═══════════════════════════════════════════════════════════════════════════ -->
<div id="menu-backdrop" class="menu-backdrop" onclick="closeMenu()"></div>
<div id="side-menu" class="side-menu">
    <div class="menu-header">
        <h3>System Menu</h3>
        <button onclick="closeMenu()" class="btn-close-menu" aria-label="Close menu">&times;</button>
    </div>
    <div class="menu-content">
        <a href="downloads/eco_quality.apk" class="menu-link apk-dl-btn" download onclick="closeMenu();">
            <svg class="icon" style="width: 16px; height: 16px; fill: currentColor;" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 576 512"><path d="M420.2 181.8c2.1-1.7 4.9-2.5 7.6-2.5 6.6 0 12 5.4 12 12 0 3.2-1.3 6.3-3.5 8.5l-50 49.3c58.2 30.5 98.4 89.2 101.4 157.9H88.3c3-68.7 43.3-127.4 101.4-157.9l-50-49.3c-2.2-2.2-3.5-5.3-3.5-8.5 0-6.6 5.4-12 12-12 2.7 0 5.5 .8 7.6 2.5l52 42.4c31.1-14.7 65.3-22.7 101.2-22.7s70 8 101.2 22.7l52-42.4zM224 288c-17.7 0-32 14.3-32 32s14.3 32 32 32 32-14.3 32-32-14.3-32-32-32zm128 0c-17.7 0-32 14.3-32 32s14.3 32 32 32 32-14.3 32-32-14.3-32-32-32z"/></svg>
            <span>Download Android App</span>
        </a>
        <button onclick="showIosInstructions(); closeMenu();" class="menu-link">
            <svg class="icon" style="width: 15px; height: 15px; fill: currentColor;" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 384 512"><path d="M318.7 268.7c-.2-36.7 16.4-64.4 50-84.8-18.8-26.9-47.2-41.7-84.7-44.6-35.5-2.8-74.3 20.7-88.5 20.7-15 0-49.4-19.7-76.4-19.7C63.3 141.2 24 184.8 8 273.5q-1.9 10.9-1.9 22.5c0 71.5 26 133.9 66.5 190.7 21.6 30.2 46.2 56 79.5 56 31 0 46-19.1 82.2-19.1 36.3 0 49.3 19 82.2 19 33.7 0 57.3-25.2 79.5-56 22-29.4 34.6-60.5 40.5-66.5-1.3-.9-58.8-22.3-58.8-90.4zM245.9 83c20.3-25.7 33-61.9 29-99-31.5 1.5-68.9 21.4-89.8 46.9-17.7 21.6-32.2 58.7-27.5 94.6 34.6 2.6 67.9-16.7 88.3-42.5z"/></svg>
            <span>Install on iOS (PWA)</span>
        </button>
        <hr class="menu-divider">
        <button onclick="nativeShare(); closeMenu();" class="menu-link">
            <span class="icon">🔗</span>
            <span>Share Live Air Quality</span>
        </button>
        <a href="export.php" class="menu-link" onclick="closeMenu();">
            <span class="icon">↓</span>
            <span>Export Historical Logs (CSV)</span>
        </a>
        <hr class="menu-divider">
        <button onclick="openFeedback(); closeMenu();" class="menu-link">
            <span class="icon">💬</span>
            <span>Submit Feedback</span>
        </button>
    </div>
</div>

<!-- Glassmorphism Modal for Sensor Explanations -->
<div id="glass-modal" class="glass-modal" onclick="closeModal(event)" role="dialog" aria-modal="true">
    <div class="glass-modal-content" onclick="event.stopPropagation()">
        <button class="close-modal-btn" onclick="closeModal(event)" aria-label="Close dialog">&times;</button>
        <div id="glass-modal-body"></div>
    </div>
</div>

<!-- Glassmorphism Modal for Stakeholder Feedback -->
<?php
$is_https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
    || (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443);
$current_host = $_SERVER['HTTP_HOST'] ?? 'eco-quality.duckdns.org';
$proto = $is_https ? 'https://' : 'http://';
$script_path = strtok($_SERVER['REQUEST_URI'] ?? '/ai_quality/dashboard.php', '?');
if (empty($script_path) || $script_path === '/') {
    $script_path = '/ai_quality/dashboard.php';
}
$feedback_next_url = (strpos($current_host, 'localhost') !== false || strpos($current_host, '127.0.0.1') !== false)
    ? 'https://eco-quality.duckdns.org/ai_quality/dashboard.php?feedback=success'
    : ($proto . $current_host . $script_path . '?feedback=success');
?>
<div id="feedback-modal" class="glass-modal" onclick="closeFeedback(event)" role="dialog" aria-modal="true">
    <div class="glass-modal-content" onclick="event.stopPropagation()">
        <button class="close-modal-btn" onclick="closeFeedback(event)" aria-label="Close feedback">&times;</button>
        <div class="tip-title">System Feedback</div>
        <p style="font-size: 12px; color: var(--muted); font-family: var(--font-mono); margin-bottom: 16px; line-height: 1.4;">
            Report observations, concerns, or inquiries. Submissions are delivered directly to the research team.
        </p>
        <form action="https://formsubmit.co/9d0f5f115f6d55431f114e43e692b709" method="POST" class="feedback-form">
            <input type="hidden" name="_captcha" value="false">
            <input type="hidden" name="_next" value="<?= htmlspecialchars($feedback_next_url) ?>">
            <input type="hidden" name="_subject" value="New Feedback from Air Quality Dashboard!">
            <input type="text" name="name" placeholder="Your Name (Optional)" class="fb-input">
            <input type="email" name="email" placeholder="Your Email Address" required class="fb-input">
            <textarea name="message" placeholder="Describe your observation, bug, or inquiry..." required class="fb-input" rows="4"></textarea>
            <button type="submit" class="fb-submit">Send Message</button>
        </form>
    </div>
</div>

<!-- Custom PWA Install Prompt -->
<div id="install-prompt" class="install-prompt">
    <div class="install-content">
        <div class="install-text">
            <strong>📲 Install Eco Quality</strong>
            <span>Add to home screen for quick mobile access</span>
        </div>
        <div class="install-actions">
            <button id="btn-install" class="btn-install">Install</button>
            <button id="btn-install-close" class="btn-close-install" aria-label="Close prompt">&times;</button>
        </div>
    </div>
</div>

<script src="assets/js/dashboard.js?v=35" defer></script>
<script>
if ('serviceWorker' in navigator) {
    window.addEventListener('load', () => {
        navigator.serviceWorker.register('./sw.js?update=29')
            .then(reg => {
                console.log('SW Registered', reg.scope);
                reg.update();
            })
            .catch(err => console.log('SW Failed', err));
    });
}
</script>

</body>
</html>
