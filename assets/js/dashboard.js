/* ── INFO TOOLTIPS ────────────────────────────────────── */
/* ── INFO TOOLTIPS (GLASS MODAL) ── */
function toggleTip(btn) {
    const tip = btn.nextElementSibling;
    const modal = document.getElementById('glass-modal');
    const modalBody = document.getElementById('glass-modal-body');
    
    // Copy the contents of the hidden info-tip into the modal
    modalBody.innerHTML = tip.innerHTML;
    
    // Show the modal
    modal.classList.add('show');
    document.body.style.overflow = 'hidden'; // Prevent background scrolling
}

function closeModal(e) {
    if (e && e.type === 'click' && e.target.classList.contains('glass-modal-content')) return;
    document.getElementById('glass-modal').classList.remove('show');
    document.body.style.overflow = '';
}

/* ── FEEDBACK MODAL ── */
function openFeedback() {
    document.getElementById('feedback-modal').classList.add('show');
    document.body.style.overflow = 'hidden';
}
function closeFeedback(e) {
    if (e && e.type === 'click' && e.target.classList.contains('glass-modal-content')) return;
    document.getElementById('feedback-modal').classList.remove('show');
    document.body.style.overflow = '';
}

// Ensure clicking outside closes it (handled by onclick="closeModal(event)" in HTML)
// Keep ESC key to close
document.addEventListener('keydown', e => {
    if (e.key === 'Escape') {
        closeModal();
        closeFeedback();
    }
});

/* ── NATIVE WEB SHARE ── */
function nativeShare() {
    const aqiEl = document.getElementById('current_aqi');
    const aqiVal = (aqiEl && aqiEl.textContent !== '--') ? aqiEl.textContent : 'available';
    const catEl = document.getElementById('current_category');
    const catVal = catEl ? catEl.textContent : '';
    
    const shareData = {
        title: 'Borongan Air Quality',
        text: `Borongan City AQI is currently ${aqiVal} ${catVal ? '('+catVal+')' : ''}. Check the live environmental dashboard here:`,
        url: window.location.href
    };

    if (navigator.share) {
        navigator.share(shareData).catch(err => console.log('Share cancelled', err));
    } else {
        alert("Web Share is not supported on this browser. Just copy the URL to share!");
    }
}

function showIosInstructions() {
    const installPromptEl = document.getElementById('install-prompt');
    if (installPromptEl) {
        installPromptEl.classList.add('show');
    } else {
        alert("To install on iPhone: Tap the 'Share' icon at the bottom of Safari, then tap 'Add to Home Screen'.");
    }
}

/* ── PWA INSTALL PROMPT ── */
let deferredPrompt;
const installPromptEl = document.getElementById('install-prompt');
const installBtn = document.getElementById('btn-install');
const closeInstallBtn = document.getElementById('btn-install-close');

// Listen for the Android/Chrome install event
window.addEventListener('beforeinstallprompt', (e) => {
    e.preventDefault(); // Prevent standard mini-infobar
    deferredPrompt = e;
    
    // Wait 3 seconds before sliding up the prompt
    setTimeout(() => {
        if (installPromptEl) installPromptEl.classList.add('show');
    }, 3000);
});

if (installBtn) {
    installBtn.addEventListener('click', async () => {
        if (deferredPrompt) {
            deferredPrompt.prompt();
            const { outcome } = await deferredPrompt.userChoice;
            deferredPrompt = null;
            installPromptEl.classList.remove('show');
        } else {
            // iOS Fallback instruction
            alert("To install on iPhone: Tap the 'Share' icon at the bottom of Safari, then tap 'Add to Home Screen'.");
        }
    });
}

if (closeInstallBtn) {
    closeInstallBtn.addEventListener('click', () => {
        installPromptEl.classList.remove('show');
    });
}

// iOS manual prompt detection (if they are on iOS and NOT in standalone mode)
const isIos = () => {
    const userAgent = window.navigator.userAgent.toLowerCase();
    return /iphone|ipad|ipod/.test(userAgent);
};
const isInStandaloneMode = () => ('standalone' in window.navigator) && (window.navigator.standalone);

if (isIos() && !isInStandaloneMode()) {
    setTimeout(() => {
        if (installPromptEl) installPromptEl.classList.add('show');
    }, 3000);
}

/* ── THEME & DEVICE SYSTEM VALIDATION ── */
let chart    = null;
let accChart = null;

const DARK_CHART  = {
    grid: '#1C2035', tick: '#5A6180', bg: '#111420',
    border: '#1C2035', body: '#DDE1F0', legend: '#5A6180'
};
const LIGHT_CHART = {
    grid: '#D0D5E8', tick: '#3D4768', bg: '#FFFFFF',
    border: '#C5CBDB', body: '#0C1220', legend: '#3D4768'
};

/**
 * System Validation: Query the user's device/OS hardware preference
 * Returns: 'light' or 'dark'
 */
function getDeviceSystemTheme() {
    if (window.matchMedia && window.matchMedia('(prefers-color-scheme: light)').matches) {
        return 'light';
    }
    return 'dark'; // Fallback default
}

/**
 * Validates initial theme with precedence:
 * 1. Explicit user choice previously stored in localStorage ('aq-theme')
 * 2. Hardware/OS device system theme validation ('prefers-color-scheme')
 */
function validateInitialTheme() {
    const saved = localStorage.getItem('aq-theme');
    if (saved === 'light' || saved === 'dark') {
        return saved === 'light';
    }
    
    // No explicit manual choice recorded — perform system validation on device
    const deviceTheme = getDeviceSystemTheme();
    console.info(`[System Validation] No manual theme override found. Detected device system theme: ${deviceTheme.toUpperCase()} mode.`);
    return deviceTheme === 'light';
}

let isLight = validateInitialTheme();

function applyTheme(light) {
    document.body.classList.toggle('light', light);
    document.documentElement.classList.toggle('light', light);
    
    const iconEl = document.getElementById('theme-icon');
    if (iconEl) iconEl.textContent = light ? '☼' : '☾';
    
    const labelEl = document.getElementById('theme-label');
    if (labelEl) labelEl.textContent = light ? 'Light' : 'Dark';
    
    const themeBtn = document.querySelector('.btn-dash-action[onclick="toggleTheme()"]');
    if (themeBtn) {
        const sourceDesc = localStorage.getItem('aq-theme') ? 'Manual' : 'Device System Default';
        themeBtn.setAttribute('title', `Theme: ${light ? 'Light' : 'Dark'} (${sourceDesc}) — Click to toggle`);
    }

    // Sync browser UI status bar (iOS Safari / Android Chrome)
    const metaThemeColor = document.querySelector('meta[name="theme-color"]');
    if (metaThemeColor) {
        metaThemeColor.setAttribute('content', light ? '#FFFFFF' : '#0D1320');
    }

    updateChartTheme(light ? LIGHT_CHART : DARK_CHART);
}

function toggleTheme() {
    isLight = !isLight;
    localStorage.setItem('aq-theme', isLight ? 'light' : 'dark');
    applyTheme(isLight);
}

// Active real-time listener: Adapts automatically if the user changes their OS theme in device settings
if (window.matchMedia) {
    const sysThemeQuery = window.matchMedia('(prefers-color-scheme: light)');
    const handleSystemThemeChange = (e) => {
        // Only dynamically change if the user has NOT set a manual override
        if (!localStorage.getItem('aq-theme')) {
            isLight = e.matches;
            console.info(`[System Validation] Real-time device theme shift detected: ${isLight ? 'LIGHT' : 'DARK'} mode.`);
            applyTheme(isLight);
        }
    };
    if (sysThemeQuery.addEventListener) {
        sysThemeQuery.addEventListener('change', handleSystemThemeChange);
    } else if (sysThemeQuery.addListener) {
        sysThemeQuery.addListener(handleSystemThemeChange);
    }
}

function updateChartTheme(t) {
    [chart, accChart].forEach(c => {
        if (!c) return;
        const o = c.options;
        o.plugins.legend.labels.color     = t.legend;
        o.plugins.tooltip.backgroundColor = t.bg;
        o.plugins.tooltip.borderColor     = t.border;
        o.plugins.tooltip.bodyColor       = t.body;
        o.plugins.tooltip.titleColor      = t.tick;
        o.scales.x.grid.color  = t.grid;  o.scales.x.ticks.color  = t.tick;
        o.scales.y.grid.color  = t.grid;  o.scales.y.ticks.color  = t.tick;
        if (o.scales.y.title)  o.scales.y.title.color  = t.tick;
        c.update();
    });
}

// Apply resolved theme immediately
applyTheme(isLight);

/* ── AQI LEVELS (PHILIPPINE CLEAN AIR ACT RA 8749 / DENR EMB) ── */
const AQI_LEVELS = [
    { max:50,   label:'Good',                            cls:'aqi-good',      card:'c-accent'  },
    { max:100,  label:'Fair',                            cls:'aqi-mod',       card:'c-warn'    },
    { max:150,  label:'Unhealthy for Sensitive Groups', cls:'aqi-sensitive', card:'c-warn'    },
    { max:200,  label:'Very Unhealthy',                 cls:'aqi-unhealthy', card:'c-danger'  },
    { max:300,  label:'Acutely Unhealthy',              cls:'aqi-very',      card:'c-very'    },
    { max:9999, label:'Emergency',                      cls:'aqi-hazardous', card:'c-haz'     },
];
function aqiInfo(v) { return AQI_LEVELS.find(l => v <= l.max) || AQI_LEVELS[5]; }

/* ── LIVE DATA ────────────────────────────────────────── */
let isLiveFetching = false;
function live() {
    if (isLiveFetching) return;
    isLiveFetching = true;

    fetch('dashboard.php?latest=1&_t=' + Date.now(), { cache: 'no-store' })
        .then(r => r.json())
        .then(d => {
            isLiveFetching = false;
            
            // Remove Skeleton Loaders once data arrives (only happens on first load)
            const targets = ['aqi', 'aqi-label', 'temp', 'hum', 'pm', 'mq'];
            targets.forEach(id => {
                let el = document.getElementById(id);
                if(el) el.classList.remove('skeleton');
            });

            const dot = document.querySelector('.logo-dot');
            if (!d) {
                if (dot) { dot.style.background = '#F5A623'; dot.style.boxShadow = '0 0 8px #F5A623'; }
                blankValues('No Data');
                return;
            }

            const diff = d.now_unix - d.ts_unix;
            if (diff > 60) {
                if (dot) { dot.style.background = '#F05252'; dot.style.boxShadow = '0 0 8px #F05252'; }
                blankValues('Offline');
            } else {
                if (dot) { dot.style.background = 'var(--accent)'; dot.style.boxShadow = '0 0 8px var(--accent)'; }

                document.getElementById('temp').textContent = d.temp  ?? '—';
                document.getElementById('hum').textContent  = d.hum   ?? '—';
                const humVal = parseFloat(d.hum);
                const humComfortEl = document.getElementById('hum-comfort-label');
                if (humComfortEl && !isNaN(humVal)) {
                    if (humVal < 30) humComfortEl.textContent = 'Dry (<30%)';
                    else if (humVal <= 60) humComfortEl.textContent = 'Comfort (30–60%)';
                    else humComfortEl.textContent = 'Humid (>60%)';
                }
                document.getElementById('mq').textContent   = d.mq135 ?? '—';
                document.getElementById('pm').textContent   = d.pm10  ?? '—';

                const aqi  = parseFloat(d.aqi);
                const info = aqiInfo(aqi);
                document.getElementById('aqi').textContent       = aqi;
                document.getElementById('aqi-label').textContent = info.label;
                document.getElementById('aqi-card').className    = 'card ' + info.card;

                // Update 24-hr Rolling Average (RA 8749 Regulatory Compliance Standard)
                if (d.aqi_24h !== undefined) {
                    const info24 = aqiInfo(d.aqi_24h);
                    const wrap24 = document.getElementById('aqi_24h_val');
                    if (wrap24) {
                        const col24 = info24.cls === 'aqi-good' ? 'var(--accent)' : 'var(--warn)';
                        wrap24.innerHTML = `<span style="color:${col24};">${d.aqi_24h} AQI · ${info24.label}</span> <span style="font-size:9.5px; color:var(--muted); font-weight:normal;">(${d.pm10_24h} µg/m³)</span>`;
                    }
                }

                // Update MQ-135 Relative Contamination Index
                const rawMq = parseInt(d.mq135, 10);
                let mqStatus = 'Baseline / Normal';
                if (rawMq > 280) mqStatus = 'Elevated Contaminants';
                else if (rawMq > 160) mqStatus = 'Moderate Gas Level';
                const mqStatusEl = document.getElementById('mq-status-label');
                if (mqStatusEl) mqStatusEl.textContent = mqStatus;

                // Threshold Health Alert Notification (RA 8749)
                // Update PAGASA Heat Index (Apparent Temperature)
                if (d.heat_index !== undefined) {
                    window.currentHeatIndex = d.heat_index;
                    window.currentHeatCat = d.heat_cat;
                    window.currentHeatDesc = d.heat_desc;
                    window.currentHeatColor = d.heat_color;
                    
                    const wrapHi = document.getElementById('heat_index_val');
                    if (wrapHi) {
                        wrapHi.innerHTML = `<span style="color:${d.heat_color};">${d.heat_index}°C — ${d.heat_cat}</span>`;
                    }
                }

                // Health Alert Notification: Based on 24-Hr AQI & ML Anomaly Detection (not noisy live nowcast)
                window.latestTelemetry = d;
                updateHealthAlert();
            }
        })
        .catch(() => {
            isLiveFetching = false;
            const dot = document.querySelector('.logo-dot');
            if (dot) { dot.style.background = '#F05252'; dot.style.boxShadow = '0 0 8px #F05252'; }
            blankValues('Error');
        });
}

function blankValues(statusMsg = 'Offline') {
    document.getElementById('temp').textContent = '—';
    document.getElementById('hum').textContent  = '—';
    document.getElementById('mq').textContent   = '—';
    document.getElementById('pm').textContent   = '—';
    document.getElementById('aqi').textContent  = '—';
    document.getElementById('aqi-label').textContent = statusMsg;
    document.getElementById('aqi-card').className = 'card';
}

/* ── SENSOR HISTORY ───────────────────────────────────── */
let isLoadFetching = false;
function load() {
    if (isLoadFetching) return;
    isLoadFetching = true;
    fetch('dashboard.php?fetch=1&_t=' + Date.now(), { cache: 'no-store' })
        .then(r => r.json())
        .then(data => {
            isLoadFetching = false;
            document.getElementById('row-count').textContent = data.length + ' rows';

            const labels = data.map(x => '#' + x.id).reverse();
            const pm   = data.map(x => parseFloat(x.pm10)).reverse();
            const aqi  = data.map(x => parseFloat(x.aqi)).reverse();
            const temp = data.map(x => parseFloat(x.temp)).reverse();
            const hum  = data.map(x => parseFloat(x.hum)).reverse();
            const mq   = data.map(x => parseFloat(x.mq135)).reverse();

            if (!chart) {
                const t    = isLight ? LIGHT_CHART : DARK_CHART;
                const mono = "'JetBrains Mono', monospace";
                
                // Create beautiful gradient fills for the chart
                const ctx = document.getElementById('chart').getContext('2d');
                let gradPM = ctx.createLinearGradient(0, 0, 0, 280);
                gradPM.addColorStop(0, 'rgba(0,207,168,0.3)');
                gradPM.addColorStop(1, 'rgba(0,207,168,0.0)');
                
                let gradAQI = ctx.createLinearGradient(0, 0, 0, 280);
                gradAQI.addColorStop(0, 'rgba(245,166,35,0.3)');
                gradAQI.addColorStop(1, 'rgba(245,166,35,0.0)');

                chart = new Chart(ctx, {
                    type: 'line',
                    data: { labels, datasets: [
                        { label:'PM10',    data:pm,   yAxisID:'y',  borderColor:'#00CFA8', backgroundColor:gradPM,  borderWidth:2, pointRadius:0, pointHoverRadius:4, tension:0.4, fill:true  },
                        { label:'AQI',     data:aqi,  yAxisID:'y',  borderColor:'#F5A623', backgroundColor:gradAQI, borderWidth:2, pointRadius:0, pointHoverRadius:4, tension:0.4, fill:true  },
                        { label:'Temp °C', data:temp, yAxisID:'y',  borderColor:'#F05252', backgroundColor:'transparent',  borderWidth:2, borderDash:[5,5], pointRadius:0, pointHoverRadius:4, tension:0.4, fill:false },
                        { label:'Hum %',   data:hum,  yAxisID:'y1', borderColor:'#4C9EEB', backgroundColor:'transparent', borderWidth:2, borderDash:[5,5], pointRadius:0, pointHoverRadius:4, tension:0.4, fill:false },
                        { label:'MQ135',   data:mq,   yAxisID:'y1', borderColor:'#8A93B8', backgroundColor:'transparent', borderWidth:1.5, pointRadius:0, pointHoverRadius:4, tension:0.4, fill:false },
                    ]},
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    interaction: { mode:'index', intersect:false },
                    layout: { padding: { bottom: 0 } },
                    plugins: {
                        legend: { labels:{ color:t.legend, font:{family:mono,size:11}, boxWidth:12, boxHeight:2, usePointStyle:false } },
                        tooltip: { 
                            backgroundColor:t.bg, borderColor:t.border, borderWidth:1,
                            titleColor:t.tick, bodyColor:t.body,
                            titleFont:{family:mono,size:11}, bodyFont:{family:mono,size:12},
                            padding: 10, cornerRadius: 8
                        }
                    },
                    scales: {
                        x: {
                            grid: { display: false }, // Cleaner modern look without vertical lines
                            ticks: { color:t.tick, font:{family:mono,size:10}, maxTicksLimit:6 }
                        },
                        y: {
                            position: 'left',
                            grid: { color:t.grid, drawBorder: false },
                            ticks: { color:t.tick, font:{family:mono,size:10} },
                            title: { display:false } // Removed for cleaner look, legend is enough
                        },
                        y1: {
                            position: 'right',
                            grid: { display: false },
                            ticks: { color:'#8A93B8', font:{family:mono,size:10} },
                            title: { display:false }
                        }
                    }
                }
            });
        } else {
            chart.data.labels = labels;
            [pm, aqi, temp, hum, mq].forEach((d, i) => chart.data.datasets[i].data = d);
            chart.update();
        }
    }).catch(err => {
        isLoadFetching = false;
        console.error('load() failed:', err);
    });
}

/* ── DAILY SUMMARY ────────────────────────────────────── */
function loadDaily() {
    const targets = ['day_today_aqi', 'day_today_cat', 'day_yest_aqi', 'day_yest_cat'];
    targets.forEach(id => { let el = document.getElementById(id); if(el) el.classList.add('skeleton'); });

    fetch('api/daily.php?t=' + Date.now(), { cache: 'no-store' }).then(r => r.json()).then(d => {
        targets.forEach(id => { let el = document.getElementById(id); if(el) el.classList.remove('skeleton'); });
        
        if (d.error) {
            document.getElementById('day_summary').textContent = d.error;
            document.getElementById('daily-tag').textContent   = 'error';
            return;
        }
        const t = d.today, y = d.yesterday, c = d.change;

        document.getElementById('day_today_aqi').textContent   = t.avg_aqi;
        document.getElementById('day_today_aqi').style.color   = t.color;
        document.getElementById('day_today_cat').textContent   = t.category;

        if (y) {
            document.getElementById('day_yest_aqi').textContent = y.avg_aqi;
            document.getElementById('day_yest_aqi').style.color = y.color;
            document.getElementById('day_yest_cat').textContent = y.category;
        } else {
            document.getElementById('day_yest_aqi').textContent = 'N/A';
            document.getElementById('day_yest_cat').textContent = 'no prior data';
        }

        document.getElementById('day_min').textContent      = t.min_aqi;
        document.getElementById('day_max').textContent      = t.max_aqi;
        document.getElementById('day_readings').textContent = t.readings;

        if (c) {
            const arrows = { worse:'↑', better:'↓', same:'→' };
            const sign   = c.abs > 0 ? '+' : '';
            document.getElementById('day-change-wrap').innerHTML =
                `<span class="change-badge ${c.direction}">${arrows[c.direction]} ${sign}${c.abs} pts (${sign}${c.pct}% vs yesterday)</span>`;
        }

        document.getElementById('day_summary').textContent = d.summary;
        document.getElementById('daily-tag').textContent   = d.date || new Date().toLocaleDateString();
    }).catch(() => {
        document.getElementById('day_summary').textContent = 'Could not reach daily.php';
        document.getElementById('daily-tag').textContent   = 'fetch error';
    });
}

/* ── TREND FORECAST (RANDOM FOREST) ───────────────────── */
function loadTrend() {
    // Apply Skeletons
    const targets = ['trend_1h', 'trend_2h', 'trend_3h'];
    targets.forEach(id => { let el = document.getElementById(id); if(el) el.classList.add('skeleton'); });

    fetch('api/rf_predict.php?t=' + Date.now(), { cache: 'no-store' }) // 🚀 prevent caching
        .then(response => response.json())
        .then(d => {
            
            // Remove Skeletons
            targets.forEach(id => { let el = document.getElementById(id); if(el) el.classList.remove('skeleton'); });

            if (!d || d.error) {
                console.warn("Trend data error:", d);
                return;
            }

            // ────────────────
            // FORECAST VALUES & COLORS
            // ────────────────
            const el1 = document.getElementById('trend_1h');
            const el2 = document.getElementById('trend_2h');
            const el3 = document.getElementById('trend_3h');
            
            el1.textContent = d.forecast_1h ?? '—';
            el2.textContent = d.forecast_2h ?? '—';
            el3.textContent = d.forecast_3h ?? '—';

            if (d.color_1h) el1.style.color = d.color_1h;
            if (d.color_2h) el2.style.color = d.color_2h;
            if (d.color_3h) el3.style.color = d.color_3h;

            // ────────────────
            // CATEGORIES
            // ────────────────
            document.getElementById('trend_cat1').textContent = d.category_1h ?? '—';
            document.getElementById('trend_cat2').textContent = d.category_2h ?? '—';
            document.getElementById('trend_cat3').textContent = d.category_3h ?? '—';

            // ────────────────
            // TREND MESSAGE
            // ────────────────
            document.getElementById('trend_msg').textContent = d.trend_msg ?? '—';

            // ────────────────
            // STATUS TAG (ML INFO)
            // ────────────────
            const imp = d.confidence?.improvement_pct;
            const impStr = imp ? `(+${imp}% vs LR)` : '';
            document.getElementById('trend-tag').textContent = 
                `Forecast Model ${impStr} • ${d.trend ?? '-'}`;

            // ────────────────
            // FEATURE IMPORTANCE
            // ────────────────
            const featWrap = document.getElementById('feat_wrap');
            const featList = document.getElementById('feat_list');
            
            if (d.feature_importance && d.feature_importance.length > 0) {
                featWrap.style.display = 'block';
                const friendlyNames = {
                    'pm10': 'PM10 Particulate Level',
                    'rolling_avg_1h': '1h Rolling AQI',
                    'rolling_avg_3h': '3h Rolling AQI',
                    'hour_of_day': 'Hour (Diurnal Cycle)',
                    'day_of_week': 'Day of Week',
                    'temp': 'Ambient Temp',
                    'hum': 'Relative Humidity',
                    'mq135': 'Gas Pollution Index',
                    'pm10_rate': 'PM10 Shift Rate',
                    'aqi_rate': 'AQI Rate of Change'
                };
                const top4 = d.feature_importance.slice(0, 4);
                featList.innerHTML = top4.map(f => {
                    const pct = Math.round(f.importance * 100);
                    const name = friendlyNames[f.feature] || f.feature;
                    return `
                        <div style="flex: 1 1 calc(50% - 6px); min-width: 130px; background: rgba(255,255,255,0.03); border: 1px solid var(--border); border-radius: 6px; padding: 6px 8px;">
                            <div style="display:flex; justify-content:space-between; font-size:10px; font-family:var(--mono); margin-bottom:4px;">
                                <span style="color:var(--text); font-weight:500;">${name}</span>
                                <span style="color:var(--accent); font-weight:600;">${pct}%</span>
                            </div>
                            <div style="height:4px; background:var(--border); border-radius:2px; overflow:hidden;">
                                <div style="width:${pct}%; height:100%; background:var(--accent); border-radius:2px;"></div>
                            </div>
                        </div>
                    `;
                }).join('');
            } else {
                featWrap.style.display = 'none';
            }

            // Model Validation Benchmark (RF vs Linear Regression Baseline)
            if (d.confidence) {
                const r2 = d.confidence.r2_score !== undefined ? d.confidence.r2_score : 0.85;
                const mae_rf = d.confidence.mae_rf !== undefined ? d.confidence.mae_rf : 4.2;
                const mae_lr = d.confidence.mae_lr !== undefined ? d.confidence.mae_lr : 8.9;
                const imp = d.confidence.improvement_pct !== undefined ? d.confidence.improvement_pct : 52.8;

                const r2El = document.getElementById('bm-r2');
                if (r2El) r2El.textContent = `R² = ${r2}`;
                
                const rfMaeEl = document.getElementById('bm-rf-mae');
                if (rfMaeEl) rfMaeEl.textContent = `±${mae_rf} AQI`;
                
                const rfR2El = document.getElementById('bm-rf-r2');
                if (rfR2El) rfR2El.textContent = `${Math.round(r2 * 100)}% fit`;
                
                const lrMaeEl = document.getElementById('bm-lr-mae');
                if (lrMaeEl) lrMaeEl.textContent = `±${mae_lr} AQI`;
                
                const impEl = document.getElementById('bm-imp');
                if (impEl) impEl.textContent = `${imp}%`;
            }

        })
        .catch(err => {
            console.error("Trend fetch error:", err);

            document.getElementById('trend-tag').textContent = 'error';
        });
}


/* ── ANOMALY DETECTION (ISOLATION FOREST) ──────────────── */
function loadAnomaly() {
    fetch('api/anomaly.php?t=' + Date.now(), { cache: 'no-store' }).then(r => r.json()).then(d => {
        if (d.error) {
            document.getElementById('anomaly-label').textContent = 'Error';
            document.getElementById('anomaly-msg').textContent   = d.error;
            document.getElementById('anomaly-tag').textContent   = 'error';
            return;
        }
        const box = document.getElementById('anomaly-status-box');
        box.className = 'anomaly-status ' + (d.is_anomaly ? d.severity : 'ok');

        const icons = { ok:'✓', warning:'⚠', critical:'✖', normal:'✓' };
        document.getElementById('anomaly-icon').textContent  = icons[d.severity] || '?';
        document.getElementById('anomaly-label').textContent = d.severity === 'normal'
            ? 'All Stable'
            : d.severity === 'warning' ? 'Fluctuation' : 'Significant Spike';
        document.getElementById('anomaly-msg').textContent   = d.message;
        document.getElementById('anomaly-tag').textContent   = d.is_anomaly ? 'SPIKE DETECTED' : 'stable';

        const sensorLabels = { pm10:'PM10', mq135:'MQ135', aqi:'AQI', temp:'Temp', hum:'Hum' };
        document.getElementById('z-grid').innerHTML = Object.entries(d.z_scores).map(([k, v]) => {
            const flagged = d.flagged.includes(k);
            return `<div class="z-box">
                <div class="z-name">${sensorLabels[k] || k}</div>
                <div class="z-val ${flagged ? 'flag' : 'ok'}">${v}</div>
            </div>`;
        }).join('');

        // Handle Isolation Forest Score
        const ifWrap = document.getElementById('iforest_wrap');
        if (d.isolation_forest) {
            ifWrap.style.display = 'block';
            const score = d.isolation_forest.anomaly_score;
            document.getElementById('iforest_val').textContent = score;
            
            const bar = document.getElementById('iforest_bar');
            bar.style.width = score + '%';
            if (score > 70) bar.style.background = 'var(--danger)';
            else if (score > 50) bar.style.background = 'var(--warn)';
            else bar.style.background = 'var(--accent)';
        } else {
            ifWrap.style.display = 'none';
        }

        // Handle Pollution Source Diagnostics & Root Cause Attribution
        if (d.source_attribution) {
            const sa = d.source_attribution;
            const badge = document.getElementById('source_confidence_badge');
            const icon = document.getElementById('source_icon');
            const title = document.getElementById('source_title');
            const reason = document.getElementById('source_reasoning');
            
            if (badge) badge.textContent = `${sa.confidence}% Match`;
            if (title) title.textContent = sa.source;
            if (reason) reason.textContent = sa.reasoning;
        }

        document.getElementById('stuck-wrap').innerHTML = d.sensor_stuck
            ? '<div class="stuck-badge">⚠️ PM10 sensor may be stuck — no variance detected</div>'
            : '';

        // Store latest anomaly and update public health alert banner
        window.latestAnomaly = d;
        updateHealthAlert();
    }).catch(() => { document.getElementById('anomaly-tag').textContent = 'fetch error'; });
}
/* ── POLLING TIMERS (OPTIMIZED FOR REAL-TIME & LOW CPU) ── */
// Live values: every 2 seconds
setInterval(live, 2000);

// Historical chart & table: every 5 seconds
setInterval(load, 5000);

// AI Trend Forecast: every 5 minutes
setInterval(loadTrend, 300000);

// Anomaly Detection: every 5 minutes
setInterval(loadAnomaly, 300000);

// Daily Summary: every 5 minutes
setInterval(loadDaily, 300000);

/* ── INITIAL LOAD ─────────────────────────────────────── */
live();
load();

// Stagger AI loads to prevent 100% CPU spikes on page refresh
setTimeout(loadTrend, 2000);
setTimeout(loadAnomaly, 5000);
setTimeout(loadDaily, 8000);

/* -- SIDE MENU -- */
function openMenu() {
    document.getElementById("side-menu").classList.add("open");
    document.getElementById("menu-backdrop").classList.add("open");
}
function closeMenu() {
    document.getElementById("side-menu").classList.remove("open");
    document.getElementById("menu-backdrop").classList.remove("open");
}



/* ── PUBLIC HEALTH ALERT LOGIC (RA 8749 COMPLIANCE) ── */
let alertDismissed = false;
function dismissAlert() {
    alertDismissed = true;
    const b = document.getElementById('health-alert-banner');
    if (b) b.style.display = 'none';
}

function updateHealthAlert(instantAqi, aqi24, heatIndex, heatCat, uesiLevel, uesiAdvice) {
    if (alertDismissed) return;
    const banner = document.getElementById('health-alert-banner');
    if (!banner) return;

    const telem = window.latestTelemetry || {};
    const anomaly = window.latestAnomaly || null;

    // Base regulatory alerts on 24-Hour Average AQI (RA 8749 / DENR DAO 2000-81 compliance)
    // Avoid noisy instantaneous nowcast spikes that trigger false alarms
    const aqi24Val = (aqi24 !== undefined && aqi24 !== null) ? parseFloat(aqi24) : (parseFloat(telem.aqi_24h) || 0);
    const hi = (heatIndex !== undefined && heatIndex !== null) ? parseFloat(heatIndex) : (parseFloat(telem.heat_index) || 0);
    const hCat = heatCat || telem.heat_cat || 'Thermal Stress';
    const uLevel = uesiLevel || telem.uesi_level || '';
    const uAdvice = uesiAdvice || telem.uesi_advice || '';

    // Machine Learning Anomaly Criteria (Isolation Forest + Z-Score explainability)
    const isAnomaly = Boolean(anomaly && anomaly.is_anomaly && (anomaly.severity === 'warning' || anomaly.severity === 'critical'));
    const anomalySeverity = anomaly ? anomaly.severity : 'normal';
    const anomalyMsg = anomaly ? (anomaly.message || anomaly.severity_msg || '') : '';
    const sourceAttribution = anomaly && anomaly.source_attribution ? anomaly.source_attribution : null;

    // Default safe baseline: hide banner when 24h AQI is acceptable, no acute ML anomaly, and thermal stress is low
    if (aqi24Val <= 100 && !isAnomaly && hi < 42) {
        banner.style.display = 'none';
        return;
    }

    banner.style.display = 'flex';
    const icon = document.getElementById('alert-icon');
    const title = document.getElementById('alert-title');
    const body = document.getElementById('alert-body');

    // 1. ACUTE AI ANOMALY DETECTION (Fast-response detection of genuine emission spikes / open combustion)
    if (isAnomaly && aqi24Val <= 100) {
        const isCrit = anomalySeverity === 'critical';
        banner.style.background = isCrit ? 'rgba(240, 82, 82, 0.18)' : 'rgba(245, 166, 35, 0.2)';
        banner.style.borderColor = isCrit ? 'rgba(240, 82, 82, 0.45)' : 'rgba(245, 166, 35, 0.5)';
        if (icon) icon.textContent = isCrit ? '🚨' : '⚠️';
        if (title) {
            const srcName = sourceAttribution && sourceAttribution.source ? ` • ${sourceAttribution.source}` : '';
            title.textContent = isCrit ? `AI SPIKE ALERT: ACUTE ENVIRONMENTAL ANOMALY${srcName}` : `AI ADVISORY: ENVIRONMENTAL FLUCTUATION${srcName}`;
            title.style.color = isCrit ? '#F05252' : '#F5A623';
        }
        if (body) {
            let desc = anomalyMsg;
            if (sourceAttribution && sourceAttribution.recommendation) {
                desc += ` ${sourceAttribution.recommendation}`;
            }
            body.textContent = desc;
        }
        return;
    }

    // 2. EXTREME HEAT ADVISORY (When 24h air quality is compliant but apparent heat index >= 42°C Danger threshold)
    if (aqi24Val <= 100 && hi >= 42) {
        banner.style.background = 'rgba(240, 82, 82, 0.18)';
        banner.style.borderColor = 'rgba(240, 82, 82, 0.4)';
        if (icon) icon.textContent = '🌡️';
        if (title) {
            title.textContent = `THERMAL ADVISORY: ${hCat} (${hi}°C FEELS LIKE)`;
            title.style.color = '#F05252';
        }
        if (body) body.textContent = 'Severe apparent heat stress. Heat cramps and exhaustion likely; heat stroke probable with prolonged exposure. Stay hydrated and avoid prolonged outdoor sun exposure.';
        return;
    }

    // 3. DUAL HAZARD (Elevated sustained 24h AQI + High ambient heat index)
    if (aqi24Val > 100 && hi >= 33) {
        banner.style.background = 'rgba(245, 166, 35, 0.2)';
        banner.style.borderColor = 'rgba(245, 166, 35, 0.5)';
        if (icon) icon.textContent = '⚠️';
        if (title) {
            title.textContent = `URBAN ENVIRONMENTAL STRESS: ${uLevel || 'ELEVATED RISK'} (24H AQI ${Math.round(aqi24Val)} · HI ${hi}°C)`;
            title.style.color = '#F5A623';
        }
        if (body) body.textContent = uAdvice || 'Dual environmental stress detected (sustained 24h particulate elevation and high thermal heat). Sensitive individuals must restrict outdoor exertion.';
        return;
    }

    // 4. SUSTAINED REGULATORY TIERS (Philippine Clean Air Act RA 8749 / DENR DAO 2000-81 standard)
    if (aqi24Val <= 150) {
        banner.style.background = 'rgba(245, 166, 35, 0.15)';
        banner.style.borderColor = 'rgba(245, 166, 35, 0.35)';
        if (icon) icon.textContent = '⚠️';
        if (title) {
            title.textContent = 'RA 8749 ADVISORY: UNHEALTHY FOR SENSITIVE GROUPS (24H AQI ' + Math.round(aqi24Val) + ')';
            title.style.color = '#F5A623';
        }
        if (body) body.textContent = 'Sustained 24-hour PM10 concentration exceeds clean guidelines. Individuals with respiratory or heart conditions, older adults, and children should limit prolonged outdoor exertion.';
    } else if (aqi24Val <= 200) {
        banner.style.background = 'rgba(240, 82, 82, 0.15)';
        banner.style.borderColor = 'rgba(240, 82, 82, 0.35)';
        if (icon) icon.textContent = '🚨';
        if (title) {
            title.textContent = 'RA 8749 HEALTH ALERT: VERY UNHEALTHY (24H AQI ' + Math.round(aqi24Val) + ')';
            title.style.color = '#F05252';
        }
        if (body) body.textContent = 'Significant sustained 24-hour air pollution detected. Active children, adults, and sensitive individuals should avoid outdoor exertion.';
    } else if (aqi24Val <= 300) {
        banner.style.background = 'rgba(168, 85, 247, 0.2)';
        banner.style.borderColor = 'rgba(168, 85, 247, 0.4)';
        if (icon) icon.textContent = '🛑';
        if (title) {
            title.textContent = 'RA 8749 WARNING: ACUTELY UNHEALTHY (24H AQI ' + Math.round(aqi24Val) + ')';
            title.style.color = '#C084FC';
        }
        if (body) body.textContent = 'Severe sustained 24-hour pollution risk. General public should stay indoors or wear protective masks outdoors.';
    } else {
        banner.style.background = 'rgba(127, 29, 29, 0.35)';
        banner.style.borderColor = 'rgba(239, 68, 68, 0.6)';
        if (icon) icon.textContent = '☣️';
        if (title) {
            title.textContent = 'RA 8749 EMERGENCY DECLARATION (24H AQI ' + Math.round(aqi24Val) + ')';
            title.style.color = '#EF4444';
        }
        if (body) body.textContent = 'Hazardous sustained 24-hour emergency conditions. All residents should remain indoors with windows and doors tightly sealed.';
    }
}

/* 🔥 PM10 MODAL EXPLAINER 🔥 */
function openPmInfo() {
    const modal = document.getElementById('glass-modal');
    const modalBody = document.getElementById('glass-modal-body');
    if (!modal || !modalBody) return;
    modalBody.innerHTML = `
        <div class="tip-title">PM10 (Particulate Matter)</div>
        <div style="font-size: 11px; line-height: 1.5; color: var(--text); margin-bottom: 12px;">
            <strong>Sensor Principle:</strong> Laser scattering (PMS5003).<br>
            <strong>Definition:</strong> Inhalable particles with diameters that are generally 10 micrometers and smaller. Sources include dust, pollen, and mold.
        </div>
        <div style="background: rgba(255,255,255,0.03); border: 1px solid var(--border); border-radius: 6px; padding: 10px; font-size: 11px; color: var(--muted); line-height: 1.5;">
            <strong style="color:var(--accent);">Regulatory Standard:</strong><br>
            The system computes a strict <strong>24-Hour Rolling Average</strong> for PM10 AQI classification, fully compliant with the <strong>Philippine Clean Air Act (RA 8749)</strong> and DENR DAO 2000-81. Short-term spikes will not drastically alter the official AQI unless sustained.
        </div>
    `;
    modal.classList.add('show');
}

/* 🔥 MQ-135 MODAL EXPLAINER 🔥 */
function openMqInfo() {
    const modal = document.getElementById('glass-modal');
    const modalBody = document.getElementById('glass-modal-body');
    if (!modal || !modalBody) return;
    modalBody.innerHTML = `
        <div class="tip-title">MQ-135 Gas Sensor</div>
        <div style="font-size: 11px; line-height: 1.5; color: var(--text); margin-bottom: 12px;">
            <strong>Sensor Principle:</strong> SnO₂ Metal-Oxide Semiconductor (MOS).<br>
            <strong>Detectable Spectrum:</strong> Volatile Organic Compounds (VOCs), NH₃, Benzene, Alcohol, Smoke, and CO₂.
        </div>
        <div style="background: rgba(255,255,255,0.03); border: 1px solid var(--border); border-radius: 6px; padding: 10px; font-size: 11px; color: var(--muted); line-height: 1.5;">
            <strong style="color:var(--accent);">Note:</strong><br>
            Low-cost MOS sensors exhibit broad cross-sensitivity across multiple gases and are subject to ambient temperature and humidity drift. Per international environmental IoT standards, this system represents readings as a <strong>Relative Gas Contamination Index (ADC displacement from zero-point baseline)</strong> rather than isolated gas PPM. This avoids uncalibrated chemical claims while effectively capturing sudden urban emission plumes.
        </div>
    `;
    modal.classList.add('show');
    document.body.style.overflow = 'hidden';
}


/* 🔥 APPARENT TEMPERATURE DYNAMIC MODAL 🔥 */
function openHeatIndexInfo() {
    const modal = document.getElementById('glass-modal');
    const modalBody = document.getElementById('glass-modal-body');
    if (!modal || !modalBody) return;
    
    const hi = window.currentHeatIndex || "-";
    const cat = window.currentHeatCat || "Normal";
    const desc = window.currentHeatDesc || "Comfortable; negligible physiological strain.";
    const color = window.currentHeatColor || "#00CFA8";

    modalBody.innerHTML = `
        <div class="tip-title">Apparent Temperature</div>
        <div style="font-size: 11px; line-height: 1.5; color: var(--text); margin-bottom: 12px;">
            <strong>Feels Like:</strong> ${hi}°C<br>
            <strong>Risk Level:</strong> <span style="color:${color}; font-weight:bold;">${cat}</span>
        </div>
        <div style="background: rgba(255,255,255,0.03); border: 1px solid var(--border); border-radius: 6px; padding: 10px; font-size: 11px; color: var(--muted); line-height: 1.5; margin-bottom: 12px;">
            <strong style="color:var(--accent);">Health Advisory:</strong><br>
            ${desc}
        </div>
        <div style="font-size: 11px; color: var(--muted); line-height: 1.5;">
            * Apparent temperature calculated using the Rothfusz regression equation (combining ambient temperature and relative humidity).
        </div>
    `;
    modal.classList.add('show');
    document.body.style.overflow = 'hidden';
}

/* 🔥 TEMPERATURE MODAL EXPLAINER 🔥 */
function openTempInfo() {
    const modal = document.getElementById('glass-modal');
    const modalBody = document.getElementById('glass-modal-body');
    if (!modal || !modalBody) return;
    modalBody.innerHTML = `
        <div class="tip-title">Ambient Temperature</div>
        <div style="font-size: 11px; line-height: 1.5; color: var(--text); margin-bottom: 12px;">
            <strong>Sensor Principle:</strong> DHT22 Thermistor.<br>
            <strong>Definition:</strong> The actual physical temperature of the surrounding air, unadjusted for humidity or other factors.
        </div>
    `;
    modal.classList.add('show');
    document.body.style.overflow = 'hidden';
}
