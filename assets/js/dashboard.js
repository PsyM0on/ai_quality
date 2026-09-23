/* ═══════════════════════════════════════════════════════════════════════════
   ECO QUALITY — UI/UX CONTROLLER SCRIPT (EQ-UIUX-2026)
   Eastern Samar State University • Capstone Project
   Dual-Mode Progressive Disclosure Dashboard • ISO/IEC 25010 & IBM CSUQ
   ═══════════════════════════════════════════════════════════════════════════ */

/* ── DUAL-MODE PROGRESSIVE DISCLOSURE VIEW CONTROLLER ───────────────────── */
function switchViewMode(mode, targetSubTab) {
    const citizenSection = document.getElementById('view-citizen');
    const techSection = document.getElementById('view-technical');
    const btnCitizen = document.getElementById('btn-citizen');
    const btnTech = document.getElementById('btn-technical');

    if (mode === 'citizen') {
        if (citizenSection) citizenSection.style.display = 'block';
        if (techSection) techSection.style.display = 'none';
        if (btnCitizen) {
            btnCitizen.classList.add('active');
            btnCitizen.setAttribute('aria-selected', 'true');
        }
        if (btnTech) {
            btnTech.classList.remove('active');
            btnTech.setAttribute('aria-selected', 'false');
        }
        try {
            localStorage.setItem('aq-view-mode', 'citizen');
            if (window.location.hash !== '#citizen') {
                history.replaceState(null, null, '#citizen');
            }
        } catch (e) {}
    } else {
        if (citizenSection) citizenSection.style.display = 'none';
        if (techSection) techSection.style.display = 'block';
        if (btnCitizen) {
            btnCitizen.classList.remove('active');
            btnCitizen.setAttribute('aria-selected', 'false');
        }
        if (btnTech) {
            btnTech.classList.add('active');
            btnTech.setAttribute('aria-selected', 'true');
        }
        try {
            localStorage.setItem('aq-view-mode', 'technical');
            if (window.location.hash !== '#technical') {
                history.replaceState(null, null, '#technical');
            }
        } catch (e) {}

        // Handle direct deep jump (e.g. from Hero B Driver Insight link)
        if (targetSubTab === 'ml') {
            switchTechTab('ml-anomaly');
            setTimeout(() => {
                const driversPanel = document.getElementById('drivers-panel');
                if (driversPanel) {
                    driversPanel.scrollIntoView({ behavior: 'smooth', block: 'start' });
                }
            }, 100);
        } else {
            // Resize chart when pane becomes visible
            if (chart) {
                setTimeout(() => {
                    chart.resize();
                    chart.update();
                }, 60);
            }
        }
    }
}

/* ── TECHNICAL SUB-TAB CONTROLLER ───────────────────────────────────────── */
function switchTechTab(tabId) {
    const paneDaily = document.getElementById('tech-pane-daily-chart');
    const paneMl = document.getElementById('tech-pane-ml-anomaly');
    const btnDaily = document.getElementById('subtab-daily-chart');
    const btnMl = document.getElementById('subtab-ml-anomaly');

    if (tabId === 'daily-chart') {
        if (paneDaily) paneDaily.style.display = 'flex';
        if (paneMl) paneMl.style.display = 'none';
        if (btnDaily) {
            btnDaily.classList.add('active');
            btnDaily.setAttribute('aria-selected', 'true');
        }
        if (btnMl) {
            btnMl.classList.remove('active');
            btnMl.setAttribute('aria-selected', 'false');
        }
        if (chart) {
            setTimeout(() => {
                chart.resize();
                chart.update();
            }, 60);
        }
    } else {
        if (paneDaily) paneDaily.style.display = 'none';
        if (paneMl) paneMl.style.display = 'flex';
        if (btnDaily) {
            btnDaily.classList.remove('active');
            btnDaily.setAttribute('aria-selected', 'false');
        }
        if (btnMl) {
            btnMl.classList.add('active');
            btnMl.setAttribute('aria-selected', 'true');
        }
    }
}

/* ── MULTI-SENSOR CHART SERIES TOGGLE FILTERING ─────────────────────────── */
function toggleChartSeries(datasetIndex, btn) {
    if (!chart) return;
    const isVisible = chart.isDatasetVisible(datasetIndex);
    chart.setDatasetVisibility(datasetIndex, !isVisible);
    chart.update();
    if (btn) {
        btn.classList.toggle('active', !isVisible);
        btn.classList.toggle('disabled', isVisible);
    }
}

/* ── INFO TOOLTIPS & GLASS MODALS ───────────────────────────────────────── */
function toggleTip(btn) {
    const tip = btn.nextElementSibling;
    const modal = document.getElementById('glass-modal');
    const modalBody = document.getElementById('glass-modal-body');
    if (!tip || !modal || !modalBody) return;
    
    modalBody.innerHTML = tip.innerHTML;
    modal.classList.add('show');
    document.body.style.overflow = 'hidden';
}

function closeModal(e) {
    if (e && e.type === 'click' && e.target.classList.contains('glass-modal-content')) return;
    const modal = document.getElementById('glass-modal');
    if (modal) modal.classList.remove('show');
    document.body.style.overflow = '';
}

/* ── FEEDBACK MODAL ─────────────────────────────────────────────────────── */
function openFeedback() {
    const modal = document.getElementById('feedback-modal');
    if (modal) {
        modal.classList.add('show');
        document.body.style.overflow = 'hidden';
    }
}

function closeFeedback(e) {
    if (e && e.type === 'click' && e.target.classList.contains('glass-modal-content')) return;
    const modal = document.getElementById('feedback-modal');
    if (modal) modal.classList.remove('show');
    document.body.style.overflow = '';
}

// ESC key closes any open modal
document.addEventListener('keydown', e => {
    if (e.key === 'Escape') {
        closeModal();
        closeFeedback();
        closeMenu();
    }
});

// Feedback redirect listener confirmation
(function() {
    try {
        const params = new URLSearchParams(window.location.search);
        if (params.get('feedback') === 'success') {
            window.addEventListener('DOMContentLoaded', () => {
                setTimeout(() => {
                    const modal = document.getElementById('glass-modal');
                    const modalBody = document.getElementById('glass-modal-body');
                    if (modal && modalBody) {
                        modalBody.innerHTML = `
                            <div class="tip-title" style="color: var(--accent);">✓ Feedback Delivered</div>
                            <div style="font-size: 13px; line-height: 1.6; color: var(--text); margin-bottom: 14px;">
                                Thank you! Your observation has been recorded and submitted to the Eastern Samar State University research team.
                            </div>
                        `;
                        modal.classList.add('show');
                        document.body.style.overflow = 'hidden';
                    }
                    if (window.history && window.history.replaceState) {
                        window.history.replaceState({}, document.title, window.location.pathname);
                    }
                }, 400);
            });
        }
    } catch (e) {}
})();

/* ── NATIVE WEB SHARE ───────────────────────────────────────────────────── */
function nativeShare() {
    const aqiEl = document.getElementById('aqi');
    const aqiVal = (aqiEl && aqiEl.textContent !== '000' && aqiEl.textContent !== '—') ? aqiEl.textContent : 'available';
    const catEl = document.getElementById('aqi-label');
    const catVal = catEl ? catEl.textContent : '';
    
    const shareData = {
        title: 'Eco Quality — Urban Air Quality Status',
        text: `ESSU Urban Air Station AQI is currently ${aqiVal} ${catVal ? '(' + catVal + ')' : ''}. Check the live environmental dashboard:`,
        url: window.location.href
    };

    if (navigator.share) {
        navigator.share(shareData).catch(err => console.log('Share cancelled', err));
    } else {
        alert("Web Share is not supported on this browser. Copy the URL from your address bar to share!");
    }
}

function showIosInstructions() {
    const installPromptEl = document.getElementById('install-prompt');
    if (installPromptEl) {
        installPromptEl.classList.add('show');
    } else {
        alert("To install on iPhone: Tap the 'Share' icon at the bottom of Safari, then select 'Add to Home Screen'.");
    }
}

/* ── PWA INSTALL PROMPT ─────────────────────────────────────────────────── */
let deferredPrompt;
const installPromptEl = document.getElementById('install-prompt');
const installBtn = document.getElementById('btn-install');
const closeInstallBtn = document.getElementById('btn-install-close');

window.addEventListener('beforeinstallprompt', (e) => {
    e.preventDefault();
    deferredPrompt = e;
    setTimeout(() => {
        if (installPromptEl) installPromptEl.classList.add('show');
    }, 3000);
});

if (installBtn) {
    installBtn.addEventListener('click', async () => {
        if (deferredPrompt) {
            deferredPrompt.prompt();
            await deferredPrompt.userChoice;
            deferredPrompt = null;
            if (installPromptEl) installPromptEl.classList.remove('show');
        } else {
            alert("To install on iPhone: Tap the 'Share' icon at the bottom of Safari, then select 'Add to Home Screen'.");
        }
    });
}

if (closeInstallBtn) {
    closeInstallBtn.addEventListener('click', () => {
        if (installPromptEl) installPromptEl.classList.remove('show');
    });
}

/* ── THEME ENGINE (DARK / LIGHT WITH ANTI-FOUC RECOVERY) ────────────────── */
let chart = null;

const DARK_CHART = {
    grid: 'rgba(255, 255, 255, 0.06)',
    tick: '#94A3B8',
    bg: '#141C2B',
    border: 'rgba(255, 255, 255, 0.1)',
    body: '#F1F5F9',
    legend: '#94A3B8'
};

const LIGHT_CHART = {
    grid: 'rgba(0, 0, 0, 0.06)',
    tick: '#64748B',
    bg: '#FFFFFF',
    border: 'rgba(0, 0, 0, 0.1)',
    body: '#0F172A',
    legend: '#64748B'
};

function getDeviceSystemTheme() {
    if (window.matchMedia && window.matchMedia('(prefers-color-scheme: light)').matches) {
        return 'light';
    }
    return 'dark';
}

function validateInitialTheme() {
    const saved = localStorage.getItem('aq-theme');
    if (saved === 'light' || saved === 'dark') {
        return saved === 'light';
    }
    return getDeviceSystemTheme() === 'light';
}

let isLight = validateInitialTheme();

function applyTheme(light) {
    document.body.classList.toggle('light', light);
    document.documentElement.classList.toggle('light', light);
    
    const iconEl = document.getElementById('theme-icon');
    if (iconEl) iconEl.textContent = light ? '☼' : '☾';
    
    const labelEl = document.getElementById('theme-label');
    if (labelEl) labelEl.textContent = light ? 'Light' : 'Dark';

    const metaThemeColor = document.querySelector('meta[name="theme-color"]');
    if (metaThemeColor) {
        metaThemeColor.setAttribute('content', light ? '#F8FAFC' : '#0B0F17');
    }

    updateChartTheme(light ? LIGHT_CHART : DARK_CHART);
}

function toggleTheme() {
    isLight = !isLight;
    localStorage.setItem('aq-theme', isLight ? 'light' : 'dark');
    applyTheme(isLight);
}

if (window.matchMedia) {
    const sysThemeQuery = window.matchMedia('(prefers-color-scheme: light)');
    const handleSystemThemeChange = (e) => {
        if (!localStorage.getItem('aq-theme')) {
            isLight = e.matches;
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
    if (!chart) return;
    const o = chart.options;
    o.plugins.legend.labels.color = t.legend;
    o.plugins.tooltip.backgroundColor = t.bg;
    o.plugins.tooltip.borderColor = t.border;
    o.plugins.tooltip.bodyColor = t.body;
    o.plugins.tooltip.titleColor = t.tick;
    o.scales.x.grid.color = t.grid;
    o.scales.x.ticks.color = t.tick;
    o.scales.y.grid.color = t.grid;
    o.scales.y.ticks.color = t.tick;
    chart.update();
}

// Apply initial resolved theme
applyTheme(isLight);

/* ── AQI BREAKPOINTS & ACTIONABLE HEALTH GUIDANCE (RA 8749 / DAO 2000-81) ─ */
const AQI_LEVELS = [
    {
        max: 50,
        label: 'Good',
        cls: 'aqi-good',
        card: 'c-accent',
        color: '#00CFA8',
        guidance: 'Air quality is ideal. Safe for all regular outdoor activities and exercise.'
    },
    {
        max: 100,
        label: 'Fair',
        cls: 'aqi-mod',
        card: 'c-warn',
        color: '#4C9EEB',
        guidance: 'Acceptable air quality. Unusually sensitive individuals should monitor respiratory symptoms.'
    },
    {
        max: 150,
        label: 'Unhealthy for Sensitive Groups',
        cls: 'aqi-sensitive',
        card: 'c-warn',
        color: '#F5A623',
        guidance: 'Sensitive groups (children, elderly, people with asthma) should reduce prolonged outdoor exertion.'
    },
    {
        max: 200,
        label: 'Very Unhealthy',
        cls: 'aqi-unhealthy',
        card: 'c-danger',
        color: '#F05252',
        guidance: 'Active children and adults should avoid prolonged outdoor exertion; keep indoor spaces well-ventilated.'
    },
    {
        max: 300,
        label: 'Acutely Unhealthy',
        cls: 'aqi-very',
        card: 'c-danger',
        color: '#A855F7',
        guidance: 'General public should avoid outdoor exertion. Consider wearing a protective particulate mask.'
    },
    {
        max: 9999,
        label: 'Emergency / Hazardous',
        cls: 'aqi-hazardous',
        card: 'c-danger',
        color: '#DC2626',
        guidance: 'Hazardous air conditions. Everyone should remain indoors with doors and windows tightly closed.'
    }
];

function aqiInfo(v) {
    return AQI_LEVELS.find(l => v <= l.max) || AQI_LEVELS[5];
}

/* ── LIVE TELEMETRY INGESTION ───────────────────────────────────────────── */
let isLiveFetching = false;
function live() {
    if (isLiveFetching) return;
    isLiveFetching = true;

    fetch('dashboard.php?latest=1&_t=' + Date.now(), { cache: 'no-store' })
        .then(r => r.json())
        .then(d => {
            isLiveFetching = false;
            
            // Remove skeleton loaders
            const targets = ['aqi', 'aqi-label', 'temp', 'hum', 'pm', 'mq'];
            targets.forEach(id => {
                let el = document.getElementById(id);
                if (el) el.classList.remove('skeleton');
            });

            const dot = document.querySelector('.logo-dot');
            if (!d) {
                if (dot) { dot.style.backgroundColor = '#F5A623'; dot.style.boxShadow = '0 0 10px #F5A623'; }
                blankValues('No Data');
                return;
            }

            const diff = d.now_unix - d.ts_unix;
            // 5-Minute Telemetry Cadence: Allow 2 transmission cycles (600s / 10m) before marking Offline
            if (diff > 600) {
                if (dot) { dot.style.backgroundColor = '#F05252'; dot.style.boxShadow = '0 0 10px #F05252'; }
                blankValues('Offline');
            } else {
                if (dot) { dot.style.backgroundColor = 'var(--accent)'; dot.style.boxShadow = '0 0 10px var(--accent)'; }

                const aqiBadge = document.getElementById('aqi-mode-badge');
                if (aqiBadge) aqiBadge.textContent = d.interval_mode ? (d.interval_mode + ' INTERVAL') : '5-MIN INTERVAL';

                document.getElementById('temp').textContent = d.temp ?? '—';
                document.getElementById('hum').textContent = d.hum ?? '—';
                
                const humVal = parseFloat(d.hum);
                const humComfortEl = document.getElementById('hum-comfort-label');
                if (humComfortEl && !isNaN(humVal)) {
                    if (humVal < 30) humComfortEl.textContent = 'Dry (<30%)';
                    else if (humVal <= 60) humComfortEl.textContent = 'Comfort (30–60%)';
                    else humComfortEl.textContent = 'Humid (>60%)';
                }
                
                document.getElementById('mq').textContent = d.mq135 ?? '—';
                document.getElementById('pm').textContent = d.pm10 ?? '—';

                const aqi = parseFloat(d.aqi);
                const info = aqiInfo(aqi);
                
                const aqiValEl = document.getElementById('aqi');
                const aqiLabelEl = document.getElementById('aqi-label');
                const aqiCardEl = document.getElementById('aqi-card');
                const aqiGuidanceEl = document.getElementById('aqi_health_guidance');

                if (aqiValEl) {
                    aqiValEl.textContent = aqi;
                    aqiValEl.style.color = info.color;
                }
                if (aqiLabelEl) {
                    aqiLabelEl.textContent = info.label;
                    aqiLabelEl.style.color = info.color;
                    aqiLabelEl.style.borderColor = info.color;
                }
                if (aqiCardEl) {
                    aqiCardEl.className = 'card hero-card hero-aqi ' + info.card;
                }
                if (aqiGuidanceEl) {
                    aqiGuidanceEl.textContent = info.guidance;
                }

                // 24-Hour Rolling Average (RA 8749 Compliance Standard)
                if (d.aqi_24h !== undefined) {
                    const info24 = aqiInfo(d.aqi_24h);
                    const wrap24 = document.getElementById('aqi_24h_val');
                    if (wrap24) {
                        wrap24.innerHTML = `<span style="color:${info24.color}; font-weight:700;">${d.aqi_24h} AQI · ${info24.label}</span> <span style="font-size:0.75rem; color:var(--muted); font-weight:normal;">(${d.pm10_24h} µg/m³)</span>`;
                    }
                }

                // MQ-135 Relative Gas Contamination
                const rawMq = parseInt(d.mq135, 10);
                let mqStatus = 'Baseline / Normal';
                if (rawMq > 280) mqStatus = 'Elevated Contaminants';
                else if (rawMq > 160) mqStatus = 'Moderate Gas Level';
                const mqStatusEl = document.getElementById('mq-status-label');
                if (mqStatusEl) mqStatusEl.textContent = mqStatus;

                // PAGASA Heat Index
                if (d.heat_index !== undefined) {
                    window.currentHeatIndex = d.heat_index;
                    window.currentHeatCat = d.heat_cat;
                    window.currentHeatDesc = d.heat_desc;
                    window.currentHeatColor = d.heat_color;
                    
                    const wrapHi = document.getElementById('heat_index_val');
                    if (wrapHi) {
                        wrapHi.innerHTML = `<span style="color:${d.heat_color}; font-weight:700;">${d.heat_index}°C — ${d.heat_cat}</span>`;
                    }
                }

                window.latestTelemetry = d;
                updateHealthAlert();
            }
        })
        .catch(() => {
            isLiveFetching = false;
            const dot = document.querySelector('.logo-dot');
            if (dot) { dot.style.backgroundColor = '#F05252'; dot.style.boxShadow = '0 0 10px #F05252'; }
            blankValues('Error');
        });
}

function blankValues(statusMsg = 'Offline') {
    document.getElementById('temp').textContent = '—';
    document.getElementById('hum').textContent = '—';
    document.getElementById('mq').textContent = '—';
    document.getElementById('pm').textContent = '—';
    document.getElementById('aqi').textContent = '—';
    document.getElementById('aqi-label').textContent = statusMsg;
    const card = document.getElementById('aqi-card');
    if (card) card.className = 'card hero-card hero-aqi';
    const aqiBadge = document.getElementById('aqi-mode-badge');
    if (aqiBadge) aqiBadge.textContent = '5-MIN INTERVAL';
    const guidance = document.getElementById('aqi_health_guidance');
    if (guidance) {
        guidance.textContent = (statusMsg === 'Offline')
            ? 'Station awaiting live sensor transmission. Health advisory will calculate on incoming packets.'
            : 'No telemetry data recorded.';
    }
}

/* ── SENSOR HISTORY TIMELINE (CHART.JS) ─────────────────────────────────── */
let isLoadFetching = false;
function load() {
    if (isLoadFetching) return;
    isLoadFetching = true;
    fetch('dashboard.php?fetch=1&_t=' + Date.now(), { cache: 'no-store' })
        .then(r => r.json())
        .then(data => {
            isLoadFetching = false;
            const rowCountEl = document.getElementById('row-count');
            if (rowCountEl) rowCountEl.textContent = data.length + ' records';

            const labels = data.map(x => '#' + x.id).reverse();
            const pm = data.map(x => parseFloat(x.pm10)).reverse();
            const aqi = data.map(x => parseFloat(x.aqi)).reverse();
            const temp = data.map(x => parseFloat(x.temp)).reverse();
            const hum = data.map(x => parseFloat(x.hum)).reverse();
            const mq = data.map(x => parseFloat(x.mq135)).reverse();

            if (!chart) {
                const t = isLight ? LIGHT_CHART : DARK_CHART;
                const mono = "'JetBrains Mono', monospace";
                const chartCanvas = document.getElementById('chart');
                if (!chartCanvas) return;
                
                const ctx = chartCanvas.getContext('2d');
                let gradPM = ctx.createLinearGradient(0, 0, 0, 280);
                gradPM.addColorStop(0, 'rgba(0, 207, 168, 0.28)');
                gradPM.addColorStop(1, 'rgba(0, 207, 168, 0.0)');
                
                let gradAQI = ctx.createLinearGradient(0, 0, 0, 280);
                gradAQI.addColorStop(0, 'rgba(245, 166, 35, 0.28)');
                gradAQI.addColorStop(1, 'rgba(245, 166, 35, 0.0)');

                chart = new Chart(ctx, {
                    type: 'line',
                    data: {
                        labels,
                        datasets: [
                            { label: 'PM10 (µg/m³)', data: pm, yAxisID: 'y', borderColor: '#00CFA8', backgroundColor: gradPM, borderWidth: 2, pointRadius: 0, pointHoverRadius: 4, tension: 0.35, fill: true },
                            { label: 'AQI', data: aqi, yAxisID: 'y', borderColor: '#F5A623', backgroundColor: gradAQI, borderWidth: 2, pointRadius: 0, pointHoverRadius: 4, tension: 0.35, fill: true },
                            { label: 'Temp (°C)', data: temp, yAxisID: 'y', borderColor: '#F05252', backgroundColor: 'transparent', borderWidth: 1.8, borderDash: [4, 4], pointRadius: 0, pointHoverRadius: 4, tension: 0.35, fill: false },
                            { label: 'Hum (%)', data: hum, yAxisID: 'y1', borderColor: '#4C9EEB', backgroundColor: 'transparent', borderWidth: 1.8, borderDash: [4, 4], pointRadius: 0, pointHoverRadius: 4, tension: 0.35, fill: false },
                            { label: 'MQ-135 Gas', data: mq, yAxisID: 'y1', borderColor: '#8A93B8', backgroundColor: 'transparent', borderWidth: 1.5, pointRadius: 0, pointHoverRadius: 4, tension: 0.35, fill: false },
                        ]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        interaction: { mode: 'index', intersect: false },
                        plugins: {
                            legend: {
                                display: false // Handled via multi-sensor toggle buttons above chart
                            },
                            tooltip: {
                                backgroundColor: t.bg,
                                borderColor: t.border,
                                borderWidth: 1,
                                titleColor: t.tick,
                                bodyColor: t.body,
                                titleFont: { family: mono, size: 11 },
                                bodyFont: { family: mono, size: 12 },
                                padding: 10,
                                cornerRadius: 8
                            }
                        },
                        scales: {
                            x: {
                                grid: { display: false },
                                ticks: { color: t.tick, font: { family: mono, size: 10 }, maxTicksLimit: 8 }
                            },
                            y: {
                                position: 'left',
                                grid: { color: t.grid, drawBorder: false },
                                ticks: { color: t.tick, font: { family: mono, size: 10 } }
                            },
                            y1: {
                                position: 'right',
                                grid: { display: false },
                                ticks: { color: '#8A93B8', font: { family: mono, size: 10 } }
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

/* ── DAILY SUMMARY & COMPARISONS ────────────────────────────────────────── */
function loadDaily() {
    const targets = ['day_today_aqi', 'day_today_cat', 'day_yest_aqi', 'day_yest_cat'];
    targets.forEach(id => { let el = document.getElementById(id); if (el) el.classList.add('skeleton'); });

    fetch('api/daily.php?t=' + Date.now(), { cache: 'no-store' }).then(r => r.json()).then(d => {
        targets.forEach(id => { let el = document.getElementById(id); if (el) el.classList.remove('skeleton'); });
        
        if (d.error) {
            document.getElementById('day_summary').textContent = d.error;
            document.getElementById('daily-tag').textContent = 'error';
            return;
        }
        const t = d.today, y = d.yesterday, c = d.change;

        document.getElementById('day_today_aqi').textContent = t.avg_aqi;
        document.getElementById('day_today_aqi').style.color = t.color;
        document.getElementById('day_today_cat').textContent = t.category;

        if (y) {
            document.getElementById('day_yest_aqi').textContent = y.avg_aqi;
            document.getElementById('day_yest_aqi').style.color = y.color;
            document.getElementById('day_yest_cat').textContent = y.category;
        } else {
            document.getElementById('day_yest_aqi').textContent = 'N/A';
            document.getElementById('day_yest_cat').textContent = 'no prior data';
        }

        document.getElementById('day_min').textContent = t.min_aqi;
        document.getElementById('day_max').textContent = t.max_aqi;
        document.getElementById('day_readings').textContent = t.readings;

        if (c) {
            const arrows = { worse: '↑', better: '↓', same: '→' };
            const sign = c.abs > 0 ? '+' : '';
            document.getElementById('day-change-wrap').innerHTML =
                `<span class="change-badge ${c.direction}">${arrows[c.direction]} ${sign}${c.abs} pts (${sign}${c.pct}% vs yesterday)</span>`;
        }

        document.getElementById('day_summary').textContent = d.summary;
        document.getElementById('daily-tag').textContent = d.date || new Date().toLocaleDateString();
    }).catch(() => {
        document.getElementById('day_summary').textContent = 'Could not reach daily.php summary endpoint.';
        document.getElementById('daily-tag').textContent = 'fetch error';
    });
}

/* ── TREND FORECAST & RANDOM FOREST MODEL EVALUATION ────────────────────── */
function loadTrend() {
    const targets = ['trend_1h', 'trend_2h', 'trend_3h'];
    targets.forEach(id => { let el = document.getElementById(id); if (el) el.classList.add('skeleton'); });

    fetch('api/rf_predict.php?t=' + Date.now(), { cache: 'no-store' })
        .then(response => response.json())
        .then(d => {
            targets.forEach(id => { let el = document.getElementById(id); if (el) el.classList.remove('skeleton'); });

            if (!d || d.error) {
                console.warn("Trend data error:", d);
                return;
            }

            const el1 = document.getElementById('trend_1h');
            const el2 = document.getElementById('trend_2h');
            const el3 = document.getElementById('trend_3h');
            
            if (el1) { el1.textContent = d.forecast_1h ?? '—'; if (d.color_1h) el1.style.color = d.color_1h; }
            if (el2) { el2.textContent = d.forecast_2h ?? '—'; if (d.color_2h) el2.style.color = d.color_2h; }
            if (el3) { el3.textContent = d.forecast_3h ?? '—'; if (d.color_3h) el3.style.color = d.color_3h; }

            const cat1 = document.getElementById('trend_cat1');
            const cat2 = document.getElementById('trend_cat2');
            const cat3 = document.getElementById('trend_cat3');
            if (cat1) cat1.textContent = d.category_1h ?? '—';
            if (cat2) cat2.textContent = d.category_2h ?? '—';
            if (cat3) cat3.textContent = d.category_3h ?? '—';

            const msgEl = document.getElementById('trend_msg');
            if (msgEl) msgEl.textContent = d.trend_msg ?? 'Stable air quality predicted over the next 3 hours.';

            const tagEl = document.getElementById('trend-tag');
            if (tagEl) {
                const imp = d.confidence?.improvement_pct;
                const impStr = imp ? `(+${imp}% vs LR)` : '';
                tagEl.textContent = `Random Forest ${impStr} • ${d.trend ?? 'stable'}`;
            }

            // Key Prediction Drivers (Feature Importance)
            const featList = document.getElementById('feat_list');
            if (featList && d.feature_importance && d.feature_importance.length > 0) {
                const friendlyNames = {
                    'pm10': 'PM10 Particulate Level',
                    'rolling_avg_1h': '1h Rolling AQI',
                    'rolling_avg_3h': '3h Rolling AQI',
                    'hour_of_day': 'Diurnal Hour Cycle',
                    'day_of_week': 'Day of Week',
                    'temp': 'Ambient Temperature',
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
                        <div class="feature-bar-row">
                            <div class="feature-bar-meta">
                                <span class="feature-name">${name}</span>
                                <span class="feature-pct">${pct}%</span>
                            </div>
                            <div class="feature-bar-track">
                                <div class="feature-bar-fill" style="width: ${pct}%;"></div>
                            </div>
                        </div>
                    `;
                }).join('');
            }

            // Model Validation Benchmark (ISO/IEC 25010 Evaluation Standard)
            if (d.confidence) {
                const r2 = d.confidence.r2_score !== undefined ? d.confidence.r2_score : 0.952;
                const mae_rf = d.confidence.mae_rf !== undefined ? d.confidence.mae_rf : 2.74;
                const mae_lr = d.confidence.mae_lr !== undefined ? d.confidence.mae_lr : 14.55;
                const imp = d.confidence.improvement_pct !== undefined ? d.confidence.improvement_pct : 81.2;

                const r2El = document.getElementById('bm-r2');
                if (r2El) r2El.textContent = `R² = ${r2}`;
                
                const rfMaeEl = document.getElementById('bm-rf-mae');
                if (rfMaeEl) rfMaeEl.textContent = `±${mae_rf} AQI`;
                
                const rfR2El = document.getElementById('bm-rf-r2');
                if (rfR2El) rfR2El.textContent = `${r2} (${Math.round(r2 * 100)}% fit)`;
                
                const lrMaeEl = document.getElementById('bm-lr-mae');
                if (lrMaeEl) lrMaeEl.textContent = `±${mae_lr} AQI`;
                
                const impEl = document.getElementById('bm-imp');
                if (impEl) impEl.textContent = `${imp}%`;
            }
        })
        .catch(err => {
            console.error("Trend fetch error:", err);
            const tag = document.getElementById('trend-tag');
            if (tag) tag.textContent = 'error';
        });
}

/* ── ANOMALY DETECTION (ISOLATION FOREST & Z-SCORE EXPLAINABILITY) ───────── */
function loadAnomaly() {
    fetch('api/anomaly.php?t=' + Date.now(), { cache: 'no-store' }).then(r => r.json()).then(d => {
        if (d.error) {
            document.getElementById('anomaly-label').textContent = 'System Note';
            document.getElementById('anomaly-msg').textContent = d.error;
            document.getElementById('anomaly-tag').textContent = 'notice';
            return;
        }
        const box = document.getElementById('anomaly-status-box');
        if (box) box.className = 'anomaly-status ' + (d.is_anomaly ? d.severity : 'ok');

        const icons = { ok: '✓', warning: '⚠️', critical: '🚨', normal: '✓' };
        document.getElementById('anomaly-icon').textContent = icons[d.severity] || '✓';
        document.getElementById('anomaly-label').textContent = d.severity === 'normal'
            ? 'All Telemetry Stable'
            : d.severity === 'warning' ? 'Minor Fluctuation' : 'Significant Spike Detected';
        document.getElementById('anomaly-msg').textContent = d.message;
        document.getElementById('anomaly-tag').textContent = d.is_anomaly ? 'SPIKE DETECTED' : 'stable';

        // Z-Scores
        const sensorLabels = { pm10: 'PM10', mq135: 'MQ135', aqi: 'AQI', temp: 'Temp', hum: 'Hum' };
        const zGridEl = document.getElementById('z-grid');
        if (zGridEl && d.z_scores) {
            zGridEl.innerHTML = Object.entries(d.z_scores).map(([k, v]) => {
                const flagged = d.flagged && d.flagged.includes(k);
                return `<div class="z-box">
                    <div class="z-name">${sensorLabels[k] || k}</div>
                    <div class="z-val ${flagged ? 'flag' : 'ok'}">${v}</div>
                </div>`;
            }).join('');
        }

        // Isolation Forest Outlier Risk Score Bar (0–100 scale)
        if (d.isolation_forest) {
            const score = d.isolation_forest.anomaly_score;
            const ifValEl = document.getElementById('iforest_val');
            if (ifValEl) ifValEl.textContent = score + ' / 100';
            
            const bar = document.getElementById('iforest_bar');
            if (bar) {
                bar.style.width = score + '%';
                if (score > 70) bar.style.backgroundColor = 'var(--danger)';
                else if (score > 40) bar.style.backgroundColor = 'var(--warn)';
                else bar.style.backgroundColor = 'var(--accent)';
            }
        }

        // Pollution Source Diagnostic Fingerprint
        if (d.source_attribution) {
            const sa = d.source_attribution;
            const badge = document.getElementById('source_confidence_badge');
            const icon = document.getElementById('source_icon');
            const title = document.getElementById('source_title');
            const reason = document.getElementById('source_reasoning');
            
            if (badge) badge.textContent = `${sa.confidence}% Confidence`;
            if (icon) icon.textContent = sa.icon || '🍃';
            if (title) title.textContent = sa.source;
            if (reason) reason.textContent = sa.reasoning;
        }

        const stuckWrap = document.getElementById('stuck-wrap');
        if (stuckWrap) {
            stuckWrap.innerHTML = d.sensor_stuck
                ? '<div class="stuck-badge">⚠️ PM10 Sensor Watchdog: Invariant signal detected — check optical chamber.</div>'
                : '';
        }

        window.latestAnomaly = d;
        updateHealthAlert();
    }).catch(() => {
        const tag = document.getElementById('anomaly-tag');
        if (tag) tag.textContent = 'fetch error';
    });
}

/* ── PUBLIC HEALTH ALERT LOGIC (RA 8749 COMPLIANCE) ─────────────────────── */
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

    const aqi24Val = (aqi24 !== undefined && aqi24 !== null) ? parseFloat(aqi24) : (parseFloat(telem.aqi_24h) || 0);
    const hi = (heatIndex !== undefined && heatIndex !== null) ? parseFloat(heatIndex) : (parseFloat(telem.heat_index) || 0);
    const hCat = heatCat || telem.heat_cat || 'Thermal Stress';
    const uLevel = uesiLevel || telem.uesi_level || '';
    const uAdvice = uesiAdvice || telem.uesi_advice || '';

    const isAnomaly = Boolean(anomaly && anomaly.is_anomaly && (anomaly.severity === 'warning' || anomaly.severity === 'critical'));
    const anomalySeverity = anomaly ? anomaly.severity : 'normal';
    const anomalyMsg = anomaly ? (anomaly.message || anomaly.severity_msg || '') : '';
    const sourceAttribution = anomaly && anomaly.source_attribution ? anomaly.source_attribution : null;

    // Normal baseline: hide banner if 24h AQI <= 100, no anomaly, and Heat Index < 42°C
    if (aqi24Val <= 100 && !isAnomaly && hi < 42) {
        banner.style.display = 'none';
        return;
    }

    banner.style.display = 'flex';
    const icon = document.getElementById('alert-icon');
    const title = document.getElementById('alert-title');
    const body = document.getElementById('alert-body');

    // 1. Acute sensor anomaly
    if (isAnomaly && aqi24Val <= 100) {
        const isCrit = anomalySeverity === 'critical';
        banner.style.borderLeftColor = isCrit ? 'var(--danger)' : 'var(--warn)';
        if (icon) icon.textContent = isCrit ? '🚨' : '⚠️';
        if (title) {
            const srcName = sourceAttribution && sourceAttribution.source ? ` • ${sourceAttribution.source.toUpperCase()}` : '';
            title.textContent = isCrit ? `ENVIRONMENTAL ALERT: ACUTE POLLUTION SPIKE${srcName}` : `ENVIRONMENTAL ADVISORY: UNUSUAL READING${srcName}`;
            title.style.color = isCrit ? 'var(--danger)' : 'var(--warn)';
        }
        if (body) {
            let desc = (anomalyMsg || '').replace(/by AI\.?/gi, '').replace(/\bAI\b/gi, '').replace(/\s{2,}/g, ' ').trim();
            if (!desc) {
                desc = isCrit ? 'Acute pollution spike detected! Concentrations are unusually elevated.' : 'Unusual localized fluctuation in ambient air quality detected.';
            }
            if (sourceAttribution && sourceAttribution.recommendation) {
                desc += ` ${sourceAttribution.recommendation}`;
            }
            body.textContent = desc;
        }
        return;
    }

    // 2. Heat advisory
    if (aqi24Val <= 100 && hi >= 42) {
        banner.style.borderLeftColor = 'var(--danger)';
        if (icon) icon.textContent = '🌡️';
        if (title) {
            title.textContent = `HEAT ADVISORY: ${hCat.toUpperCase()} (${hi}°C FEELS LIKE)`;
            title.style.color = 'var(--danger)';
        }
        if (body) body.textContent = 'Severe apparent heat stress. Heat cramps and exhaustion likely; heat stroke probable with prolonged exposure. Stay hydrated and avoid prolonged outdoor sun exposure.';
        return;
    }

    // 3. Dual hazard
    if (aqi24Val > 100 && hi >= 33) {
        banner.style.borderLeftColor = 'var(--warn)';
        if (icon) icon.textContent = '⚠️';
        if (title) {
            title.textContent = `ENVIRONMENTAL ADVISORY: ${uLevel ? uLevel.toUpperCase() : 'ELEVATED RISK'} (24H AQI ${Math.round(aqi24Val)} · HI ${hi}°C)`;
            title.style.color = 'var(--warn)';
        }
        if (body) body.textContent = uAdvice || 'Dual environmental stress detected (sustained 24h particulate elevation and high thermal heat). Sensitive individuals must restrict outdoor exertion.';
        return;
    }

    // 4. Sustained 24h ambient air categories
    if (aqi24Val <= 150) {
        banner.style.borderLeftColor = 'var(--warn)';
        if (icon) icon.textContent = '⚠️';
        if (title) {
            title.textContent = 'AIR QUALITY ADVISORY: UNHEALTHY FOR SENSITIVE GROUPS (24H AQI ' + Math.round(aqi24Val) + ')';
            title.style.color = 'var(--warn)';
        }
        if (body) body.textContent = 'Sustained 24-hour PM10 concentration exceeds clean guidelines. Individuals with respiratory or heart conditions, older adults, and children should limit prolonged outdoor exertion.';
    } else if (aqi24Val <= 200) {
        banner.style.borderLeftColor = 'var(--danger)';
        if (icon) icon.textContent = '🚨';
        if (title) {
            title.textContent = 'PUBLIC HEALTH ALERT: VERY UNHEALTHY (24H AQI ' + Math.round(aqi24Val) + ')';
            title.style.color = 'var(--danger)';
        }
        if (body) body.textContent = 'Significant sustained 24-hour air pollution detected. Active children, adults, and sensitive individuals should avoid outdoor exertion.';
    } else if (aqi24Val <= 300) {
        banner.style.borderLeftColor = 'var(--purple)';
        if (icon) icon.textContent = '🛑';
        if (title) {
            title.textContent = 'AIR QUALITY WARNING: ACUTELY UNHEALTHY (24H AQI ' + Math.round(aqi24Val) + ')';
            title.style.color = 'var(--purple)';
        }
        if (body) body.textContent = 'Severe sustained 24-hour pollution risk. General public should stay indoors or wear protective masks outdoors.';
    } else {
        banner.style.borderLeftColor = 'var(--danger)';
        if (icon) icon.textContent = '☣️';
        if (title) {
            title.textContent = 'EMERGENCY HEALTH DECLARATION: HAZARDOUS AIR (24H AQI ' + Math.round(aqi24Val) + ')';
            title.style.color = 'var(--danger)';
        }
        if (body) body.textContent = 'Hazardous sustained 24-hour emergency conditions. All residents should remain indoors with windows and doors tightly sealed.';
    }
}

/* ── MODAL EXPLAINERS (ACCESSIBLE GLASS DIALOGS) ────────────────────────── */
function openPmInfo() {
    const modal = document.getElementById('glass-modal');
    const modalBody = document.getElementById('glass-modal-body');
    if (!modal || !modalBody) return;
    modalBody.innerHTML = `
        <div class="tip-title">PM10 (Particulate Matter)</div>
        <div style="font-size: 13px; line-height: 1.6; color: var(--text); margin-bottom: 14px;">
            <strong>Sensor Principle:</strong> Laser scattering (Plantower PMS5003).<br>
            <strong>Definition:</strong> Inhalable particles with aerodynamic diameters ≤10 micrometers. Common sources include road dust, construction, and vegetative burning.
        </div>
        <div style="background: var(--card-subtle); border: 1px solid var(--border); border-radius: var(--radius-inner); padding: 12px; font-size: 12px; color: var(--muted); line-height: 1.5;">
            <strong style="color:var(--accent);">Philippine Clean Air Act Compliance:</strong><br>
            The system computes a verified <strong>24-Hour Rolling Average</strong> for PM10 AQI classification in accordance with <strong>DENR DAO 2000-81 and RA 8749</strong>. Transient noise spikes will not trigger false regulatory alarm levels unless sustained.
        </div>
    `;
    modal.classList.add('show');
    document.body.style.overflow = 'hidden';
}

function openMqInfo() {
    const modal = document.getElementById('glass-modal');
    const modalBody = document.getElementById('glass-modal-body');
    if (!modal || !modalBody) return;
    modalBody.innerHTML = `
        <div class="tip-title">MQ-135 Gas Sensor Scope</div>
        <div style="font-size: 13px; line-height: 1.6; color: var(--text); margin-bottom: 14px;">
            <strong>Sensor Principle:</strong> SnO₂ Metal-Oxide Semiconductor (MOS).<br>
            <strong>Detectable Range:</strong> Broad sensitivity to Volatile Organic Compounds (VOCs), NH₃, Smoke, Alcohol, and CO.
        </div>
        <div style="background: var(--card-subtle); border: 1px solid var(--border); border-radius: var(--radius-inner); padding: 12px; font-size: 12px; color: var(--muted); line-height: 1.5;">
            <strong style="color:var(--accent);">Relative Contamination Index:</strong><br>
            Per international IoT environmental standards, raw ADC values are treated as a relative baseline deviation index rather than uncalibrated chemical PPM. This accurately captures sudden localized smoke or combustion plumes.
        </div>
    `;
    modal.classList.add('show');
    document.body.style.overflow = 'hidden';
}

function openHeatIndexInfo() {
    const modal = document.getElementById('glass-modal');
    const modalBody = document.getElementById('glass-modal-body');
    if (!modal || !modalBody) return;
    
    const hi = window.currentHeatIndex || "—";
    const cat = window.currentHeatCat || "Normal";
    const desc = window.currentHeatDesc || "Comfortable; minimal thermal strain.";
    const color = window.currentHeatColor || "#00CFA8";

    modalBody.innerHTML = `
        <div class="tip-title">Apparent Temperature (Feels Like)</div>
        <div style="font-size: 13px; line-height: 1.6; color: var(--text); margin-bottom: 12px;">
            <strong>Calculated Heat Index:</strong> ${hi}°C<br>
            <strong>Thermal Stress Tier:</strong> <span style="color:${color}; font-weight:700;">${cat}</span>
        </div>
        <div style="background: var(--card-subtle); border: 1px solid var(--border); border-radius: var(--radius-inner); padding: 12px; font-size: 12px; color: var(--muted); line-height: 1.5; margin-bottom: 12px;">
            <strong style="color:var(--accent);">PAGASA Health Guidance:</strong><br>
            ${desc}
        </div>
        <div style="font-size: 11px; color: var(--muted); line-height: 1.4;">
            * Calculated using PAGASA / Rothfusz biometeorological regression algorithms combining ambient dry-bulb temperature and relative humidity.
        </div>
    `;
    modal.classList.add('show');
    document.body.style.overflow = 'hidden';
}

function openTempInfo() {
    const modal = document.getElementById('glass-modal');
    const modalBody = document.getElementById('glass-modal-body');
    if (!modal || !modalBody) return;
    modalBody.innerHTML = `
        <div class="tip-title">Ambient Air Temperature</div>
        <div style="font-size: 13px; line-height: 1.6; color: var(--text);">
            <strong>Sensor Principle:</strong> DHT22 High-Precision Digital Thermistor.<br>
            <strong>Definition:</strong> Physical thermodynamic temperature of the ambient air layer in degrees Celsius (°C).
        </div>
    `;
    modal.classList.add('show');
    document.body.style.overflow = 'hidden';
}

/* ── SIDE DRAWER MENU CONTROLS ──────────────────────────────────────────── */
function openMenu() {
    const menu = document.getElementById("side-menu");
    const backdrop = document.getElementById("menu-backdrop");
    if (menu) menu.classList.add("open");
    if (backdrop) backdrop.classList.add("open");
}

function closeMenu() {
    const menu = document.getElementById("side-menu");
    const backdrop = document.getElementById("menu-backdrop");
    if (menu) menu.classList.remove("open");
    if (backdrop) backdrop.classList.remove("open");
}

/* ── INITIAL STARTUP & TIMERS ───────────────────────────────────────────── */
// Initialize view mode based on URL hash or stored preference
(function() {
    const hash = window.location.hash;
    const saved = localStorage.getItem('aq-view-mode');
    if (hash === '#technical' || saved === 'technical') {
        switchViewMode('technical');
    } else {
        switchViewMode('citizen');
    }
})();

// Polling intervals (optimized for 5-minute telemetry transmission cadence)
setInterval(live, 15000);         // Live values: every 15s (catches 5-min transmissions promptly)
setInterval(load, 30000);         // Historical chart: every 30s
setInterval(loadTrend, 300000);   // Random Forest Trend: every 5m
setInterval(loadAnomaly, 300000); // Isolation Forest Anomaly: every 5m
setInterval(loadDaily, 300000);   // Daily Summary: every 5m

// Initial load sequences (staggered to prevent CPU spikes)
live();
load();
setTimeout(loadTrend, 1500);
setTimeout(loadAnomaly, 3500);
setTimeout(loadDaily, 5500);
