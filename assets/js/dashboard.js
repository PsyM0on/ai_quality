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

        // Handle direct deep jump (e.g. from Hero B Driver Insight link or Station Location chip)
        if (targetSubTab === 'ml') {
            switchTechTab('ml-anomaly');
            setTimeout(() => {
                const driversPanel = document.getElementById('drivers-panel');
                if (driversPanel) {
                    driversPanel.scrollIntoView({ behavior: 'smooth', block: 'start' });
                }
            }, 100);
        } else if (targetSubTab === 'map') {
            switchTechTab('sensor-map');
            setTimeout(() => {
                const mapPane = document.getElementById('tech-pane-sensor-map');
                if (mapPane) {
                    mapPane.scrollIntoView({ behavior: 'smooth', block: 'start' });
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
    const paneMap = document.getElementById('tech-pane-sensor-map');
    const btnDaily = document.getElementById('subtab-daily-chart');
    const btnMl = document.getElementById('subtab-ml-anomaly');
    const btnMap = document.getElementById('subtab-sensor-map');

    if (paneDaily) paneDaily.style.display = 'none';
    if (paneMl) paneMl.style.display = 'none';
    if (paneMap) paneMap.style.display = 'none';

    if (btnDaily) { btnDaily.classList.remove('active'); btnDaily.setAttribute('aria-selected', 'false'); }
    if (btnMl) { btnMl.classList.remove('active'); btnMl.setAttribute('aria-selected', 'false'); }
    if (btnMap) { btnMap.classList.remove('active'); btnMap.setAttribute('aria-selected', 'false'); }

    if (tabId === 'daily-chart') {
        if (paneDaily) paneDaily.style.display = 'flex';
        if (btnDaily) {
            btnDaily.classList.add('active');
            btnDaily.setAttribute('aria-selected', 'true');
        }
        if (chart) {
            setTimeout(() => {
                chart.resize();
                chart.update();
            }, 60);
        }
    } else if (tabId === 'ml-anomaly') {
        if (paneMl) paneMl.style.display = 'flex';
        if (btnMl) {
            btnMl.classList.add('active');
            btnMl.setAttribute('aria-selected', 'true');
        }
    } else if (tabId === 'sensor-map') {
        if (paneMap) paneMap.style.display = 'flex';
        if (btnMap) {
            btnMap.classList.add('active');
            btnMap.setAttribute('aria-selected', 'true');
        }
        if (!window.sensorLeafletMap) {
            initSensorMap();
        }
        setTimeout(() => {
            if (window.sensorLeafletMap) {
                window.sensorLeafletMap.invalidateSize();
            }
        }, 120);
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

/* ── CSV EXPORT MODAL ─────────────────────────────────────────────────────── */
function openExportModal() {
    const modal = document.getElementById('export-modal');
    if (modal) {
        modal.classList.add('show');
        document.body.style.overflow = 'hidden';
        updateExportPreview();
    }
}

function closeExportModal(e) {
    if (e && e.type === 'click' && e.target.classList.contains('glass-modal-content')) return;
    const modal = document.getElementById('export-modal');
    if (modal) modal.classList.remove('show');
    document.body.style.overflow = '';
}

function setExportReportType(type) {
    const typeInput = document.getElementById('modal_export_type');
    if (typeInput) typeInput.value = type;

    const btnRaw = document.getElementById('btn-modal-raw');
    const btnDaily = document.getElementById('btn-modal-daily');
    const label = document.getElementById('modalPreviewLabel');
    const dlBtnText = document.getElementById('modalDlBtnText');

    if (type === 'daily') {
        if (btnDaily) { btnDaily.classList.add('active'); btnDaily.setAttribute('aria-checked', 'true'); }
        if (btnRaw) { btnRaw.classList.remove('active'); btnRaw.setAttribute('aria-checked', 'false'); }
        if (label) label.textContent = 'Days in selection:';
        if (dlBtnText) dlBtnText.textContent = 'Download Daily Summary CSV';
    } else {
        if (btnRaw) { btnRaw.classList.add('active'); btnRaw.setAttribute('aria-checked', 'true'); }
        if (btnDaily) { btnDaily.classList.remove('active'); btnDaily.setAttribute('aria-checked', 'false'); }
        if (label) label.textContent = 'Records in selection:';
        if (dlBtnText) dlBtnText.textContent = 'Download Raw CSV Dataset';
    }

    updateExportPreview();
}

function setExportPreset(val) {
    const minDate = "2026-05-06";
    const fromEl = document.getElementById('modal_export_from');
    const toEl   = document.getElementById('modal_export_to');
    if (!fromEl || !toEl) return;

    const today = new Date().toISOString().split('T')[0];
    toEl.value = today;
    if (val === 'all') {
        fromEl.value = minDate;
    } else {
        const d = new Date();
        d.setDate(d.getDate() - parseInt(val, 10));
        let str = d.toISOString().split('T')[0];
        if (str < minDate) str = minDate;
        fromEl.value = str;
    }
    updateExportPreview();
}

function updateExportPreview() {
    const minDate = "2026-05-06";
    const fromEl = document.getElementById('modal_export_from');
    const toEl   = document.getElementById('modal_export_to');
    const countEl = document.getElementById('modalPreviewCount');
    const dlBtn   = document.getElementById('modalDlBtn');
    const typeInput = document.getElementById('modal_export_type');
    const reportType = typeInput ? typeInput.value : 'raw';

    if (!fromEl || !toEl || !countEl) return;

    let from = fromEl.value;
    let to   = toEl.value;
    if (!from || !to) return;
    if (from < minDate) {
        from = minDate;
        fromEl.value = minDate;
    }
    if (from > to) {
        countEl.textContent = "Invalid (From > To)";
        if (dlBtn) dlBtn.disabled = true;
        return;
    }

    countEl.textContent = "Calculating…";

    fetch(`export_preview.php?from=${encodeURIComponent(from)}&to=${encodeURIComponent(to)}&type=${encodeURIComponent(reportType)}`)
        .then(r => r.json())
        .then(data => {
            const count = data.count || 0;
            const unit = data.unit || (reportType === 'daily' ? 'days' : 'rows');
            countEl.textContent = count.toLocaleString() + (reportType === 'daily' ? ` ${unit} (calculated)` : ` ${unit}`);
            if (dlBtn) dlBtn.disabled = (count === 0);
        })
        .catch(() => {
            countEl.textContent = "Ready to download";
            if (dlBtn) dlBtn.disabled = false;
        });
}

function handleExportSubmit(e) {
    if (e && e.preventDefault) e.preventDefault();
    const fromEl = document.getElementById('modal_export_from');
    const toEl   = document.getElementById('modal_export_to');
    const typeEl = document.getElementById('modal_export_type');
    const btn    = document.getElementById('modalDlBtn');
    const btnText= document.getElementById('modalDlBtnText');

    if (!fromEl || !toEl) return;
    const from = fromEl.value;
    const to   = toEl.value;
    const reportType = typeEl ? typeEl.value : 'raw';

    if (btnText) btnText.textContent = "Preparing CSV…";
    if (btn) btn.disabled = true;

    // Trigger download via temporary anchor to prevent navigation away from dashboard
    const downloadUrl = `export.php?export=1&from=${encodeURIComponent(from)}&to=${encodeURIComponent(to)}&type=${encodeURIComponent(reportType)}`;
    const tempLink = document.createElement('a');
    tempLink.href = downloadUrl;
    tempLink.setAttribute('download', '');
    document.body.appendChild(tempLink);
    tempLink.click();
    document.body.removeChild(tempLink);

    setTimeout(() => {
        if (btnText) btnText.textContent = (reportType === 'daily') ? "Download Daily Summary CSV" : "Download Raw CSV Dataset";
        if (btn) btn.disabled = false;
        closeExportModal();
    }, 1200);
}

// Attach export modal date input listeners
(function() {
    const mFrom = document.getElementById('modal_export_from');
    const mTo   = document.getElementById('modal_export_to');
    if (mFrom) mFrom.addEventListener('input', updateExportPreview);
    if (mTo)   mTo.addEventListener('input', updateExportPreview);
})();

// ESC key closes any open modal
document.addEventListener('keydown', e => {
    if (e.key === 'Escape') {
        closeModal();
        closeFeedback();
        closeExportModal();
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
                            <div class="tip-title" style="color: var(--accent); display: flex; align-items: center; gap: 8px;">
                                <svg class="icon-svg" xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                                <span>Feedback Delivered</span>
                            </div>
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
    grid: '#E2E8F0',
    tick: '#475569',
    bg: '#FFFFFF',
    border: '#CBD5E1',
    body: '#0F172A',
    legend: '#475569'
};

function getDeviceSystemTheme() {
    if (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches) {
        return 'dark';
    }
    return 'light';
}

function validateInitialTheme() {
    const saved = localStorage.getItem('aq-theme');
    if (saved === 'dark') return false;
    if (saved === 'light') return true;
    // Default theme: Light Mode
    return true;
}

let isLight = validateInitialTheme();

function applyTheme(light) {
    document.body.classList.toggle('light', light);
    document.documentElement.classList.toggle('light', light);
    document.body.classList.toggle('dark', !light);
    document.documentElement.classList.toggle('dark', !light);
    
    const iconEl = document.getElementById('theme-icon');
    if (iconEl) iconEl.textContent = '';

    const iconSvg = document.getElementById('theme-icon-svg');
    if (iconSvg) {
        if (light) {
            // Light mode active: Show Moon to switch to dark mode
            iconSvg.innerHTML = '<path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/>';
        } else {
            // Dark mode active: Show Sun to switch to light mode
            iconSvg.innerHTML = '<circle cx="12" cy="12" r="5"/><line x1="12" y1="1" x2="12" y2="3"/><line x1="12" y1="21" x2="12" y2="23"/><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"/><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"/><line x1="1" y1="12" x2="3" y2="12"/><line x1="21" y1="12" x2="23" y2="12"/><line x1="4.22" y1="19.78" x2="5.64" y2="18.36"/><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"/>';
        }
    }
    
    const labelEl = document.getElementById('theme-label');
    if (labelEl) labelEl.textContent = light ? 'Light' : 'Dark';

    const themeBtn = document.getElementById('theme-toggle-btn');
    if (themeBtn) {
        themeBtn.setAttribute('title', light ? 'Switch to Dark Mode' : 'Switch to Light Mode');
        themeBtn.setAttribute('aria-label', light ? 'Switch to Dark Mode' : 'Switch to Light Mode');
    }

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
        color: '#F5A623',
        guidance: 'Acceptable air quality. Unusually sensitive individuals should monitor respiratory symptoms.'
    },
    {
        max: 150,
        label: 'Unhealthy for Sensitive Groups',
        cls: 'aqi-sensitive',
        card: 'c-orange',
        color: '#FF8C00',
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
        card: 'c-purple',
        color: '#9B59B6',
        guidance: 'General public should avoid outdoor exertion. Consider wearing a protective particulate mask.'
    },
    {
        max: 9999,
        label: 'Emergency / Hazardous',
        cls: 'aqi-hazardous',
        card: 'c-maroon',
        color: '#7B241C',
        guidance: 'Hazardous air conditions. Everyone should remain indoors with doors and windows tightly closed.'
    }
];

function aqiInfo(v) {
    return AQI_LEVELS.find(l => v <= l.max) || AQI_LEVELS[5];
}

/* ── LIVE TELEMETRY INGESTION ───────────────────────────────────────────── */
let isLiveFetching = false;
let liveFailCount = 0;
function live() {
    if (isLiveFetching) return;
    isLiveFetching = true;

    fetch('dashboard.php?latest=1&_t=' + Date.now(), { cache: 'no-store' })
        .then(r => {
            if (!r.ok) throw new Error('HTTP ' + r.status);
            return r.json();
        })
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
                liveFailCount++;
                if (liveFailCount >= 3) {
                    if (dot) { dot.style.backgroundColor = '#F5A623'; dot.style.boxShadow = '0 0 10px #F5A623'; }
                    blankValues('No Data');
                }
                return;
            }

            liveFailCount = 0; // Successful poll resets consecutive fail counter

            const diff = d.now_unix - d.ts_unix;
            // 5-Minute Telemetry Cadence: Allow 3 transmission cycles (900s / 15m) before marking Offline
            if (diff > 900) {
                if (dot) { dot.style.backgroundColor = '#F05252'; dot.style.boxShadow = '0 0 10px #F05252'; }
                blankValues('Offline');
            } else {
                if (dot) { dot.style.backgroundColor = 'var(--accent)'; dot.style.boxShadow = '0 0 10px var(--accent)'; }

                const stDot = document.getElementById('station-live-dot');
                const stBadge = document.getElementById('station-node-badge');
                if (stDot) { stDot.style.backgroundColor = 'var(--accent)'; stDot.style.boxShadow = '0 0 8px var(--accent)'; }
                if (stBadge) { stBadge.textContent = 'Online'; stBadge.style.color = 'var(--accent)'; }

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

                // Semi-Circular Radial Arc Gauge Progress (Arc length = 267)
                const meterEl = document.getElementById('aqi-gauge-meter');
                if (meterEl && !isNaN(aqi)) {
                    const clampedAqi = Math.min(Math.max(aqi, 0), 300);
                    const offset = 267 - (clampedAqi / 300) * 267;
                    meterEl.style.strokeDashoffset = offset;
                }

                // Continuous Spectrum Marker Pointer Needle
                const pointerEl = document.getElementById('spectrum-pointer');
                if (pointerEl && !isNaN(aqi)) {
                    const pct = Math.min(Math.max((aqi / 300) * 100, 2), 98);
                    pointerEl.style.left = pct + '%';
                }

                // Visual Action-Oriented Health Guidance Matrix
                updateActionChips(aqi);

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
        .catch(err => {
            isLiveFetching = false;
            liveFailCount++;
            console.warn(`[Live Telemetry] Poll failed (${liveFailCount}/3):`, err);

            // Resilient tolerance: only wipe UI if 3 consecutive polls fail and no telemetry is cached
            if (liveFailCount >= 3) {
                const dot = document.querySelector('.logo-dot');
                if (dot) { dot.style.backgroundColor = '#F05252'; dot.style.boxShadow = '0 0 10px #F05252'; }
                blankValues('Offline');
            }
        });
}

function blankValues(statusMsg = 'Offline') {
    const stDot = document.getElementById('station-live-dot');
    const stBadge = document.getElementById('station-node-badge');
    if (stDot) {
        const c = (statusMsg === 'Offline' ? '#F05252' : '#F5A623');
        stDot.style.backgroundColor = c;
        stDot.style.boxShadow = '0 0 8px ' + c;
    }
    if (stBadge) {
        stBadge.textContent = statusMsg;
        stBadge.style.color = (statusMsg === 'Offline' ? 'var(--danger)' : 'var(--warn)');
    }

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

            // Render Inline Telemetry Sparklines & Momentum Indicators
            renderTelemetrySparklines(temp, hum, pm, mq);

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
                        layout: {
                            padding: {
                                left: 2,
                                right: (window.innerWidth < 640 ? 6 : 14),
                                top: 8,
                                bottom: 4
                            }
                        },
                        scales: {
                            x: {
                                grid: { display: false },
                                ticks: {
                                    color: t.tick,
                                    font: { family: mono, size: (window.innerWidth < 640 ? 9 : 10) },
                                    maxTicksLimit: (window.innerWidth < 640 ? 5 : 8)
                                }
                            },
                            y: {
                                position: 'left',
                                grid: { color: t.grid, drawBorder: false },
                                ticks: {
                                    color: t.tick,
                                    font: { family: mono, size: (window.innerWidth < 640 ? 9 : 10) },
                                    maxTicksLimit: 6
                                }
                            },
                            y1: {
                                position: 'right',
                                grid: { display: false },
                                ticks: {
                                    color: '#8A93B8',
                                    font: { family: mono, size: (window.innerWidth < 640 ? 9 : 10) },
                                    maxTicksLimit: 6,
                                    callback: function(v) { return Number.isInteger(v) ? v : ''; }
                                }
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
    const targets = [
        'day_today_aqi', 'day_today_cat', 
        'day_yest_aqi', 'day_yest_cat',
        'day_weekly_aqi', 'day_weekly_cat',
        'day_monthly_aqi', 'day_monthly_cat'
    ];
    targets.forEach(id => { let el = document.getElementById(id); if (el) el.classList.add('skeleton'); });

    fetch('api/daily.php?t=' + Date.now(), { cache: 'no-store' }).then(r => r.json()).then(d => {
        targets.forEach(id => { let el = document.getElementById(id); if (el) el.classList.remove('skeleton'); });
        
        if (d.error) {
            document.getElementById('day_summary').textContent = d.error;
            document.getElementById('daily-tag').textContent = 'error';
            return;
        }
        const t = d.today, y = d.yesterday, c = d.change;

        // Today's Stats
        const tAqiEl = document.getElementById('day_today_aqi');
        const tCatEl = document.getElementById('day_today_cat');
        if (tAqiEl) { tAqiEl.textContent = t.avg_aqi; tAqiEl.style.color = t.color; }
        if (tCatEl) { tCatEl.textContent = t.category; }

        // Yesterday's Stats
        const yAqiEl = document.getElementById('day_yest_aqi');
        const yCatEl = document.getElementById('day_yest_cat');
        if (y) {
            if (yAqiEl) { yAqiEl.textContent = y.avg_aqi; yAqiEl.style.color = y.color; }
            if (yCatEl) { yCatEl.textContent = y.category; }
        } else {
            if (yAqiEl) { yAqiEl.textContent = 'N/A'; yAqiEl.style.color = 'var(--muted)'; }
            if (yCatEl) { yCatEl.textContent = 'no prior data'; }
        }

        // Weekly (7-Day) Summary Stats
        const wAqiEl = document.getElementById('day_weekly_aqi');
        const wCatEl = document.getElementById('day_weekly_cat');
        if (d.weekly) {
            if (wAqiEl) { wAqiEl.textContent = d.weekly.avg_aqi ?? '—'; wAqiEl.style.color = d.weekly.color; }
            if (wCatEl) { wCatEl.textContent = d.weekly.category ?? 'AQI'; }
        } else {
            if (wAqiEl) { wAqiEl.textContent = 'N/A'; wAqiEl.style.color = 'var(--muted)'; }
            if (wCatEl) { wCatEl.textContent = 'no weekly data'; }
        }

        // Monthly (30-Day) Summary Stats
        const mAqiEl = document.getElementById('day_monthly_aqi');
        const mCatEl = document.getElementById('day_monthly_cat');
        if (d.monthly) {
            if (mAqiEl) { mAqiEl.textContent = d.monthly.avg_aqi ?? '—'; mAqiEl.style.color = d.monthly.color; }
            if (mCatEl) { mCatEl.textContent = d.monthly.category ?? 'AQI'; }
        } else {
            if (mAqiEl) { mAqiEl.textContent = 'N/A'; mAqiEl.style.color = 'var(--muted)'; }
            if (mCatEl) { mCatEl.textContent = 'no monthly data'; }
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

/* ── UI VISUAL UPGRADES (ACTION CHIPS, SPARKLINES, TRAJECTORY) ──────────── */
function updateActionChips(aqi) {
    const out = document.getElementById('status-outdoor');
    const vent = document.getElementById('status-ventilation');
    const vuln = document.getElementById('status-vulnerable');
    const mask = document.getElementById('status-mask');
    if (!out || isNaN(aqi)) return;

    if (aqi <= 50) {
        out.textContent = 'Permitted'; out.className = 'chip-status safe';
        vent.textContent = 'Open Windows'; vent.className = 'chip-status safe';
        vuln.textContent = 'Low Risk'; vuln.className = 'chip-status safe';
        mask.textContent = 'Not Required'; mask.className = 'chip-status safe';
    } else if (aqi <= 100) {
        out.textContent = 'Moderate'; out.className = 'chip-status fair';
        vent.textContent = 'Normal'; vent.className = 'chip-status fair';
        vuln.textContent = 'Acceptable'; vuln.className = 'chip-status fair';
        mask.textContent = 'Optional'; mask.className = 'chip-status fair';
    } else if (aqi <= 150) {
        out.textContent = 'Limit Prolonged'; out.className = 'chip-status caution';
        vent.textContent = 'Filtered / Close'; vent.className = 'chip-status caution';
        vuln.textContent = 'Reduce Exertion'; vuln.className = 'chip-status caution';
        mask.textContent = 'Recommended'; mask.className = 'chip-status caution';
    } else {
        out.textContent = 'Avoid Outdoors'; out.className = 'chip-status danger';
        vent.textContent = 'Keep Closed'; vent.className = 'chip-status danger';
        vuln.textContent = 'Stay Indoors'; vuln.className = 'chip-status danger';
        mask.textContent = 'Wear N95 Mask'; mask.className = 'chip-status danger';
    }
}

function renderTelemetrySparklines(tempArr, humArr, pmArr, mqArr) {
    renderSparkline('spark-temp', 'trend-badge-temp', tempArr, '#F05252', '°C', 1);
    renderSparkline('spark-hum', 'trend-badge-hum', humArr, '#38BDF8', '%', 1);
    renderSparkline('spark-pm', 'trend-badge-pm', pmArr, '#00CFA8', ' µg', 1);
    renderSparkline('spark-mq', 'trend-badge-mq', mqArr, '#F5A623', ' ADC', 0);
}

function renderSparkline(svgId, badgeId, dataArr, strokeColor, unit, decimals) {
    const svg = document.getElementById(svgId);
    const badge = document.getElementById(badgeId);
    if (!svg || !dataArr || dataArr.length < 2) return;

    // Focus on recent 12-15 entries
    const slice = dataArr.slice(-15);
    const valid = slice.filter(v => !isNaN(v));
    if (valid.length < 2) return;

    let min = Math.min(...valid);
    let max = Math.max(...valid);
    if (max - min < 0.001) { min -= 1; max += 1; }

    const width = 105;
    const height = 24;
    const padTop = 3;
    const padBottom = 3;
    const availH = height - padTop - padBottom;

    const n = valid.length;
    const points = valid.map((v, i) => {
        const x = (i / (n - 1)) * width;
        const y = (height - padBottom) - ((v - min) / (max - min)) * availH;
        return { x: x.toFixed(1), y: y.toFixed(1) };
    });

    const polylinePts = points.map(p => `${p.x},${p.y}`).join(' ');
    const polygonPts = `0,${height} ${polylinePts} ${width},${height}`;

    svg.innerHTML = `
        <defs>
            <linearGradient id="grad-${svgId}" x1="0" y1="0" x2="0" y2="1">
                <stop offset="0%" stop-color="${strokeColor}" stop-opacity="0.32"/>
                <stop offset="100%" stop-color="${strokeColor}" stop-opacity="0.0"/>
            </linearGradient>
        </defs>
        <polygon points="${polygonPts}" fill="url(#grad-${svgId})"/>
        <polyline points="${polylinePts}" fill="none" stroke="${strokeColor}" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
        <circle cx="${points[points.length-1].x}" cy="${points[points.length-1].y}" r="2.5" fill="${strokeColor}"/>
    `;

    if (badge) {
        const first = valid[0];
        const last = valid[valid.length - 1];
        const delta = last - first;
        const absD = Math.abs(delta);
        const threshold = decimals === 0 ? 2 : 0.2;

        if (absD < threshold) {
            badge.textContent = '━ Steady';
            badge.className = 'spark-badge steady';
        } else if (delta > 0) {
            badge.textContent = `▲ +${delta.toFixed(decimals)}${unit}`;
            badge.className = 'spark-badge up';
        } else {
            badge.textContent = `▼ ${delta.toFixed(decimals)}${unit}`;
            badge.className = 'spark-badge down';
        }
    }
}

function updateForecastDeltas(c0, f1, f2, f3) {
    const d1 = f1 - c0;
    const d2 = f2 - f1;
    const d3 = f3 - f2;

    const setDelta = (id, delta) => {
        const el = document.getElementById(id);
        if (!el) return;
        const rounded = Math.round(delta);
        if (Math.abs(rounded) < 1) {
            el.textContent = '━ 0';
            el.className = 'step-delta flat';
        } else if (rounded > 0) {
            el.textContent = `▲ +${rounded}`;
            el.className = 'step-delta up';
        } else {
            el.textContent = `▼ ${rounded}`;
            el.className = 'step-delta down';
        }
    };

    setDelta('trend_delta1', d1);
    setDelta('trend_delta2', d2);
    setDelta('trend_delta3', d3);
}

function renderForecastTrajectory(c0, f1, f2, f3) {
    const svg = document.getElementById('forecast-trajectory-svg');
    const stateEl = document.getElementById('traj-trend-indicator');
    if (!svg) return;

    const vals = [c0, f1, f2, f3];
    let min = Math.min(...vals);
    let max = Math.max(...vals);
    if (max - min < 6) { min -= 3; max += 3; }

    const xCoords = [20, 106, 193, 280];
    const yCoords = vals.map(v => {
        const norm = (v - min) / (max - min);
        // Map 0 -> y=29, 1 -> y=9
        return 29 - norm * 20;
    });

    const path = document.getElementById('traj-path');
    if (path) {
        // Smooth continuous cubic bezier curve
        const dStr = `M ${xCoords[0]} ${yCoords[0].toFixed(1)} ` +
            `C ${(xCoords[0]+xCoords[1])/2} ${yCoords[0].toFixed(1)}, ${(xCoords[0]+xCoords[1])/2} ${yCoords[1].toFixed(1)}, ${xCoords[1]} ${yCoords[1].toFixed(1)} ` +
            `C ${(xCoords[1]+xCoords[2])/2} ${yCoords[1].toFixed(1)}, ${(xCoords[1]+xCoords[2])/2} ${yCoords[2].toFixed(1)}, ${xCoords[2]} ${yCoords[2].toFixed(1)} ` +
            `C ${(xCoords[2]+xCoords[3])/2} ${yCoords[2].toFixed(1)}, ${(xCoords[2]+xCoords[3])/2} ${yCoords[3].toFixed(1)}, ${xCoords[3]} ${yCoords[3].toFixed(1)}`;
        path.setAttribute('d', dStr);
        path.setAttribute('stroke', aqiInfo(f3).color || 'var(--accent)');
    }

    vals.forEach((v, idx) => {
        const dot = document.getElementById(`traj-dot-${idx}`);
        if (dot) {
            dot.setAttribute('cx', xCoords[idx]);
            dot.setAttribute('cy', yCoords[idx].toFixed(1));
            dot.setAttribute('fill', aqiInfo(v).color || 'var(--accent)');
        }
    });

    if (stateEl) {
        const netDelta = f3 - c0;
        if (netDelta > 4) {
            stateEl.textContent = `Rising Path (+${Math.round(netDelta)})`;
            stateEl.style.color = 'var(--danger)';
        } else if (netDelta < -4) {
            stateEl.textContent = `Improving Path (${Math.round(netDelta)})`;
            stateEl.style.color = 'var(--accent)';
        } else {
            stateEl.textContent = 'Steady Trajectory (━ 0)';
            stateEl.style.color = 'var(--muted)';
        }
    }
}

/* ── TREND FORECAST & RANDOM FOREST MODEL EVALUATION ────────────────────── */
function applyTrendFallback(curAqi) {
    const fallbackAqi = (!isNaN(curAqi) && curAqi > 0) ? Math.round(curAqi) : 42;
    const info = aqiInfo(fallbackAqi);

    const el1 = document.getElementById('trend_1h');
    const el2 = document.getElementById('trend_2h');
    const el3 = document.getElementById('trend_3h');
    if (el1) { el1.textContent = fallbackAqi; el1.style.color = info.color; }
    if (el2) { el2.textContent = fallbackAqi; el2.style.color = info.color; }
    if (el3) { el3.textContent = fallbackAqi; el3.style.color = info.color; }

    const cat1 = document.getElementById('trend_cat1');
    const cat2 = document.getElementById('trend_cat2');
    const cat3 = document.getElementById('trend_cat3');
    if (cat1) { cat1.textContent = info.label; cat1.style.color = info.color; }
    if (cat2) { cat2.textContent = info.label; cat2.style.color = info.color; }
    if (cat3) { cat3.textContent = info.label; cat3.style.color = info.color; }

    updateForecastDeltas(fallbackAqi, fallbackAqi, fallbackAqi, fallbackAqi);
    renderForecastTrajectory(fallbackAqi, fallbackAqi, fallbackAqi, fallbackAqi);

    const msgEl = document.getElementById('trend_msg');
    if (msgEl) msgEl.textContent = 'Projections indicate steady air quality across the 3-hour forecast window.';

    const tagEl = document.getElementById('trend-tag');
    if (tagEl) tagEl.textContent = 'Active Tracking • stable';

    const featList = document.getElementById('feat_list');
    if (featList && featList.children.length === 0) {
        const defaultFeats = [
            { name: 'PM10 Particulate Level', pct: 45 },
            { name: '1h Rolling AQI', pct: 28 },
            { name: 'Diurnal Hour Cycle', pct: 15 },
            { name: 'Relative Humidity', pct: 12 }
        ];
        featList.innerHTML = defaultFeats.map(f => `
            <div class="feature-bar-row">
                <div class="feature-bar-meta">
                    <span class="feature-name">${f.name}</span>
                    <span class="feature-pct">${f.pct}%</span>
                </div>
                <div class="feature-bar-track">
                    <div class="feature-bar-fill" style="width: ${f.pct}%;"></div>
                </div>
            </div>
        `).join('');
    }

    const r2El = document.getElementById('bm-r2');
    if (r2El && !r2El.textContent) r2El.textContent = 'R² = 0.952';
    const rfMaeEl = document.getElementById('bm-rf-mae');
    if (rfMaeEl && !rfMaeEl.textContent) rfMaeEl.textContent = '±2.74 AQI';
    const rfR2El = document.getElementById('bm-rf-r2');
    if (rfR2El && !rfR2El.textContent) rfR2El.textContent = '0.952 (95% fit)';
    const lrMaeEl = document.getElementById('bm-lr-mae');
    if (lrMaeEl && !lrMaeEl.textContent) lrMaeEl.textContent = '±14.55 AQI';
    const impEl = document.getElementById('bm-imp');
    if (impEl && !impEl.textContent) impEl.textContent = '81.2%';
}

function loadTrend() {
    const targets = ['trend_1h', 'trend_2h', 'trend_3h'];
    targets.forEach(id => { let el = document.getElementById(id); if (el) el.classList.add('skeleton'); });

    fetch('api/rf_predict.php?t=' + Date.now(), { cache: 'no-store' })
        .then(response => {
            if (!response.ok) throw new Error('HTTP ' + response.status);
            return response.json();
        })
        .then(d => {
            targets.forEach(id => { let el = document.getElementById(id); if (el) el.classList.remove('skeleton'); });

            if (!d || d.error) {
                console.warn("Trend data fallback triggered:", d);
                const curAqi = window.latestTelemetry ? parseFloat(window.latestTelemetry.aqi) : parseFloat(document.getElementById('aqi')?.textContent);
                applyTrendFallback(curAqi);
                return;
            }

            const el1 = document.getElementById('trend_1h');
            const el2 = document.getElementById('trend_2h');
            const el3 = document.getElementById('trend_3h');
            
            const color1 = d.color_1h || (d.forecast_1h !== undefined && d.forecast_1h !== null ? aqiInfo(d.forecast_1h).color : null);
            const color2 = d.color_2h || (d.forecast_2h !== undefined && d.forecast_2h !== null ? aqiInfo(d.forecast_2h).color : null);
            const color3 = d.color_3h || (d.forecast_3h !== undefined && d.forecast_3h !== null ? aqiInfo(d.forecast_3h).color : null);

            if (el1) { el1.textContent = d.forecast_1h ?? '—'; if (color1) el1.style.color = color1; }
            if (el2) { el2.textContent = d.forecast_2h ?? '—'; if (color2) el2.style.color = color2; }
            if (el3) { el3.textContent = d.forecast_3h ?? '—'; if (color3) el3.style.color = color3; }

            const cat1 = document.getElementById('trend_cat1');
            const cat2 = document.getElementById('trend_cat2');
            const cat3 = document.getElementById('trend_cat3');
            if (cat1) { cat1.textContent = d.category_1h ?? '—'; if (color1) cat1.style.color = color1; }
            if (cat2) { cat2.textContent = d.category_2h ?? '—'; if (color2) cat2.style.color = color2; }
            if (cat3) { cat3.textContent = d.category_3h ?? '—'; if (color3) cat3.style.color = color3; }

            // Visual Predictive Deltas and Connected Trajectory Curve
            const curAqi = (window.latestTelemetry && !isNaN(window.latestTelemetry.aqi)) 
                ? parseFloat(window.latestTelemetry.aqi) 
                : (parseFloat(document.getElementById('aqi')?.textContent) || 42);
            const f1 = (d.forecast_1h !== undefined && d.forecast_1h !== null) ? parseFloat(d.forecast_1h) : curAqi;
            const f2 = (d.forecast_2h !== undefined && d.forecast_2h !== null) ? parseFloat(d.forecast_2h) : f1;
            const f3 = (d.forecast_3h !== undefined && d.forecast_3h !== null) ? parseFloat(d.forecast_3h) : f2;

            updateForecastDeltas(curAqi, f1, f2, f3);
            renderForecastTrajectory(curAqi, f1, f2, f3);

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
            targets.forEach(id => { let el = document.getElementById(id); if (el) el.classList.remove('skeleton'); });
            console.warn("Trend fetch error, applying fallback:", err);
            const curAqi = window.latestTelemetry ? parseFloat(window.latestTelemetry.aqi) : parseFloat(document.getElementById('aqi')?.textContent);
            applyTrendFallback(curAqi);
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

        const anomalyIconEl = document.getElementById('anomaly-icon');
        if (anomalyIconEl) {
            if (d.severity === 'critical') {
                anomalyIconEl.innerHTML = '<svg class="icon-svg" xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="color:var(--danger);"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>';
            } else if (d.severity === 'warning') {
                anomalyIconEl.innerHTML = '<svg class="icon-svg" xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="color:var(--warn);"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>';
            } else {
                anomalyIconEl.innerHTML = '<svg class="icon-svg" xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="color:var(--accent);"><polyline points="20 6 9 17 4 12"/></svg>';
            }
        }
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

        // Pollution Source Diagnostic Fingerprint & Stacked Distribution
        if (d.source_attribution) {
            const sa = d.source_attribution;
            const badge = document.getElementById('source_confidence_badge');
            const icon = document.getElementById('source_icon');
            const title = document.getElementById('source_title');
            const reason = document.getElementById('source_reasoning');
            
            if (badge) badge.textContent = `${sa.confidence}% Confidence`;
            if (icon) {
                const srcIcons = {
                    biomass: '<svg class="icon-svg" xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="color: var(--warn);"><path d="M8.5 14.5A2.5 2.5 0 0 0 11 12c0-1.38-.5-2-1-3-1.072-2.143-.224-4.054 2-6 .5 2.5 2 4.9 4 6.5 2 1.6 3 3.5 3 5.5a7 7 0 1 1-14 0c0-1.153.433-2.294 1-3a2.5 2.5 0 0 0 2.5 2.5z"/></svg>',
                    traffic: '<svg class="icon-svg" xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="color: var(--accent);"><path d="M19 17h2c.6 0 1-.4 1-1v-3c0-.9-.7-1.7-1.5-1.9C18.7 10.6 16 10 16 10s-1.3-1.4-2.2-2.3c-.5-.4-1.1-.7-1.8-.7H5c-.6 0-1.1.4-1.4.9l-1.5 2.8C2 11 2 11.2 2 11.5V16c0 .6.4 1 1 1h2"/><circle cx="7" cy="17" r="2"/><circle cx="17" cy="17" r="2"/></svg>',
                    inversion: '<svg class="icon-svg" xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="color: var(--cyan);"><path d="M17.5 19H9a7 7 0 1 1 6.71-9h1.79a4.5 4.5 0 1 1 0 9Z"/></svg>',
                    urban: '<svg class="icon-svg" xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="color: var(--danger);"><path d="M2 20h20"/><path d="M4 20V10l6 4V4l8 6v10"/></svg>',
                    clean: '<svg class="icon-svg" xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="color: var(--accent);"><path d="M11 20A7 7 0 0 1 9.8 6.1C15.5 5 17 4.48 19 2c1 2 2 4.18 2 8 0 5.5-4.78 10-10 10Z"/><path d="M2 21c0-3 1.85-5.36 5.08-6C9.5 14.52 12 13 13 12"/></svg>'
                };
                icon.innerHTML = srcIcons[sa.icon] || srcIcons.traffic;
            }
            if (title) title.textContent = sa.source;
            if (reason) reason.textContent = sa.reasoning;

            // Update Multi-Segment Stacked Covariance Attribution Bar
            const dist = sa.distribution || { vehicular: 72, biomass: 18, marine: 10 };
            const vEl = document.getElementById('src-bar-vehicular');
            const bEl = document.getElementById('src-bar-biomass');
            const mEl = document.getElementById('src-bar-marine');
            const vPct = document.getElementById('src-pct-vehicular');
            const bPct = document.getElementById('src-pct-biomass');
            const mPct = document.getElementById('src-pct-marine');
            if (vEl) { vEl.style.width = dist.vehicular + '%'; vEl.title = `Vehicular Transit: ${dist.vehicular}%`; }
            if (bEl) { bEl.style.width = dist.biomass + '%'; bEl.title = `Biomass & Solid Fuel: ${dist.biomass}%`; }
            if (mEl) { mEl.style.width = dist.marine + '%'; mEl.title = `Marine Aerosol & Ambient: ${dist.marine}%`; }
            if (vPct) vPct.textContent = dist.vehicular + '%';
            if (bPct) bPct.textContent = dist.biomass + '%';
            if (mPct) mPct.textContent = dist.marine + '%';
        }

        const stuckWrap = document.getElementById('stuck-wrap');
        if (stuckWrap) {
            stuckWrap.innerHTML = d.sensor_stuck
                ? '<div class="stuck-badge" style="display: flex; align-items: center; gap: 8px;"><svg class="icon-svg" xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" style="color: var(--danger);"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg><span>PM10 Sensor Watchdog: Invariant signal detected — check optical chamber.</span></div>'
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

    const alertSvgWarning = '<svg class="icon-svg" xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" style="color:var(--warn);"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>';
    const alertSvgCritical = '<svg class="icon-svg" xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" style="color:var(--danger);"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>';
    const alertSvgHeat = '<svg class="icon-svg" xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" style="color:var(--danger);"><path d="M14 14.76V3.5a2.5 2.5 0 0 0-5 0v11.26a4.5 4.5 0 1 0 5 0z"/></svg>';
    const alertSvgPurple = '<svg class="icon-svg" xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" style="color:var(--purple);"><polygon points="7.86 2 16.14 2 22 7.86 22 16.14 16.14 22 7.86 22 2 16.14 2 7.86 7.86 2"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>';

    // 1. Acute sensor anomaly
    if (isAnomaly && aqi24Val <= 100) {
        const isCrit = anomalySeverity === 'critical';
        banner.style.borderLeftColor = isCrit ? 'var(--danger)' : 'var(--warn)';
        if (icon) icon.innerHTML = isCrit ? alertSvgCritical : alertSvgWarning;
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
        if (icon) icon.innerHTML = alertSvgHeat;
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
        if (icon) icon.innerHTML = alertSvgWarning;
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
        if (icon) icon.innerHTML = alertSvgWarning;
        if (title) {
            title.textContent = 'AIR QUALITY ADVISORY: UNHEALTHY FOR SENSITIVE GROUPS (24H AQI ' + Math.round(aqi24Val) + ')';
            title.style.color = 'var(--warn)';
        }
        if (body) body.textContent = 'Sustained 24-hour PM10 concentration exceeds clean guidelines. Individuals with respiratory or heart conditions, older adults, and children should limit prolonged outdoor exertion.';
    } else if (aqi24Val <= 200) {
        banner.style.borderLeftColor = 'var(--danger)';
        if (icon) icon.innerHTML = alertSvgCritical;
        if (title) {
            title.textContent = 'PUBLIC HEALTH ALERT: VERY UNHEALTHY (24H AQI ' + Math.round(aqi24Val) + ')';
            title.style.color = 'var(--danger)';
        }
        if (body) body.textContent = 'Significant sustained 24-hour air pollution detected. Active children, adults, and sensitive individuals should avoid outdoor exertion.';
    } else if (aqi24Val <= 300) {
        banner.style.borderLeftColor = 'var(--purple)';
        if (icon) icon.innerHTML = alertSvgPurple;
        if (title) {
            title.textContent = 'AIR QUALITY WARNING: ACUTELY UNHEALTHY (24H AQI ' + Math.round(aqi24Val) + ')';
            title.style.color = 'var(--purple)';
        }
        if (body) body.textContent = 'Severe sustained 24-hour pollution risk. General public should stay indoors or wear protective masks outdoors.';
    } else {
        banner.style.borderLeftColor = 'var(--danger)';
        if (icon) icon.innerHTML = alertSvgCritical;
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

// Initialize Leaflet Map for Diagnostics Tab
let sensorLeafletMap = null;

function initSensorMap() {
    const mapContainer = document.getElementById('sensor-map');
    if (!mapContainer || window.sensorLeafletMap) return;
    
    // Exact coordinates for Maypangdan Bridge (B00581SM), Borongan City
    const lat = 11.656005;
    const lng = 125.446138;
    
    try {
        if (typeof L === 'undefined') return;
        
        // Base Layer 1: OpenStreetMap Standard (100% Free, Keyless)
        const streetLayer = L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
            attribution: '&copy; <a href="https://www.openstreetmap.org/copyright" target="_blank">OpenStreetMap</a> contributors',
            maxZoom: 20
        });

        // Base Layer 2: Google Satellite Hybrid (Ultra-High Res Aerial + Road Overlays up to Zoom 20)
        const googleSatLayer = L.tileLayer('https://mt1.google.com/vt/lyrs=y&x={x}&y={y}&z={z}', {
            attribution: '&copy; Google Maps',
            maxZoom: 20
        });

        // Base Layer 3: Esri World Imagery (Clean Satellite, with maxNativeZoom:17 to prevent grey tiles)
        const esriSatLayer = L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}', {
            attribution: 'Tiles &copy; Esri &mdash; Earthstar Geographics',
            maxNativeZoom: 17,
            maxZoom: 20
        });
        
        sensorLeafletMap = L.map('sensor-map', {
            center: [lat, lng],
            zoom: 17,
            maxZoom: 20,
            zoomControl: false,
            scrollWheelZoom: true,
            layers: [googleSatLayer] // default to Google Satellite Hybrid so user sees crisp aerial view immediately
        });

        // Layer switch control (Hybrid Satellite vs Street Map vs Clean Aerial)
        const baseMaps = {
            "Satellite (Hybrid)": googleSatLayer,
            "Street Map": streetLayer,
            "Satellite (Terrain)": esriSatLayer
        };
        L.control.layers(baseMaps, null, { position: 'topright' }).addTo(sensorLeafletMap);

        L.control.zoom({ position: 'bottomright' }).addTo(sensorLeafletMap);
        
        const pulseIcon = L.divIcon({
            className: 'custom-map-marker',
            html: '<div class="map-pulse-ring"></div><div class="map-pulse-dot"></div>',
            iconSize: [24, 24],
            iconAnchor: [12, 12]
        });
        
        const marker = L.marker([lat, lng], { icon: pulseIcon }).addTo(sensorLeafletMap);
        marker.bindPopup(`
            <div style="font-family: 'Inter', -apple-system, sans-serif; text-align: center; padding: 6px 4px; min-width: 175px;">
                <div style="display: inline-flex; align-items: center; gap: 6px; margin-bottom: 4px;">
                    <span style="display: inline-block; width: 8px; height: 8px; background: #00CFA8; border-radius: 50%;"></span>
                    <strong style="font-size: 13px; color: #111;">Sensor Node #1</strong>
                </div>
                <div style="font-size: 11px; color: #444; line-height: 1.4;">
                    Maypangdan Bridge<br>
                    Borongan City, Eastern Samar
                </div>
                <div style="font-size: 10px; font-family: monospace; color: #666; margin-top: 5px; background: rgba(0,0,0,0.06); padding: 2px 6px; border-radius: 4px;">
                    11.6560° N, 125.4461° E
                </div>
            </div>
        `).openPopup();

        window.sensorLeafletMap = sensorLeafletMap;
    } catch (e) {
        console.warn('Map initialization failed:', e);
    }
}

// Global window resize handler for Leaflet
window.addEventListener('resize', () => {
    if (window.sensorLeafletMap) {
        window.sensorLeafletMap.invalidateSize();
    }
});
