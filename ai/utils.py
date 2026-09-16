"""
utils.py — Shared utilities for the AI-Driven Environmental Monitoring System.
Import from this module instead of copy-pasting logic across scripts.
"""

import mysql.connector

# ── DATABASE ──────────────────────────────────────────────────────────────────

DB = dict(host="localhost", user="aq_user", password="aq_secure_2024", database="air_quality")

def get_conn():
    """Return a new MySQL connection using project credentials."""
    return mysql.connector.connect(**DB)


# ── AQI HELPERS ───────────────────────────────────────────────────────────────

# ── PHILIPPINE CLEAN AIR ACT (RA 8749 / DENR EMB) PM10 BREAKPOINTS ──────────
_AQI_BREAKPOINTS = [
    (0.0,   54.0,   0,  50),   # Good
    (55.0,  154.0,  51, 100),  # Fair
    (155.0, 254.0, 101, 150),  # Unhealthy for Sensitive Groups
    (255.0, 354.0, 151, 200),  # Very Unhealthy
    (355.0, 424.0, 201, 300),  # Acutely Unhealthy
    (425.0, 604.0, 301, 500),  # Emergency
]

def calc_aqi(pm10: float) -> int:
    """Compute AQI from PM10 using Philippine Clean Air Act (RA 8749 / DENR) breakpoints."""
    for c_low, c_high, i_low, i_high in _AQI_BREAKPOINTS:
        if c_low <= pm10 <= c_high:
            aqi = (i_high - i_low) / (c_high - c_low) * (pm10 - c_low) + i_low
            return round(int(aqi))
    return 500  # Cap at Emergency


def get_category(aqi: float) -> str:
    """Return Philippine DENR EMB AQI category label."""
    if aqi <= 50:    return "Good"
    elif aqi <= 100: return "Fair"
    elif aqi <= 150: return "Unhealthy for Sensitive Groups"
    elif aqi <= 200: return "Very Unhealthy"
    elif aqi <= 300: return "Acutely Unhealthy"
    else:            return "Emergency"


def get_color(aqi: float) -> str:
    """Return hex color for Philippine AQI level."""
    if aqi <= 50:    return "#00CFA8"
    elif aqi <= 100: return "#F5A623"
    elif aqi <= 150: return "#FF8C00"
    elif aqi <= 200: return "#F05252"
    elif aqi <= 300: return "#9B59B6"
    else:            return "#7B241C"


def get_advice(aqi: float) -> str:
    """Return Philippine DENR EMB cautionary health advice."""
    if aqi <= 50:    return "Air quality is satisfactory. No air pollution health risks (DENR Good)."
    elif aqi <= 100: return "Air quality is acceptable (Fair). Unusually sensitive individuals should consider limiting prolonged outdoor exertion."
    elif aqi <= 150: return "Unhealthy for Sensitive Groups. People with respiratory or heart disease, the elderly, and children should limit outdoor exertion."
    elif aqi <= 200: return "Very Unhealthy. People with respiratory illness should avoid outdoor exertion; everyone else should limit prolonged exposure."
    elif aqi <= 300: return "Acutely Unhealthy. People with respiratory disease (asthma) must stay indoors; general public should avoid outdoor exertion."
    else:            return "EMERGENCY. Everyone should avoid outdoor exertion; remain indoors with doors and windows closed."


# ── FIRE ALERT ────────────────────────────────────────────────────────────────
# Based on NFPA 72 / UL 521 fixed-temperature heat detector standards:
#   57°C–74°C (135°F–165°F) → Early fire warning
#   > 74°C                  → Critical / confirmed fire conditions

def get_fire_alert(celsius: float) -> dict:
    """
    Return a fire alert dict based on NFPA 72 / UL 521 thresholds.
    Keys: level, label, temp_c, temp_f, message
    """
    temp_f = round(celsius * 9 / 5 + 32, 1)
    celsius = round(celsius, 1)

    if 57.0 <= celsius <= 74.0:
        return {
            "level":   "warning",
            "label":   "Fire Early Warning",
            "temp_c":  celsius,
            "temp_f":  temp_f,
            "message": (
                f"Temperature ({celsius}°C / {temp_f}°F) is within the "
                "fixed-temperature heat detector threshold (57–74°C / 135–165°F). "
                "High likelihood of fire. Check for smoke or overheating equipment."
            )
        }
    elif celsius > 74.0:
        return {
            "level":   "critical",
            "label":   "Critical Fire Alert",
            "temp_c":  celsius,
            "temp_f":  temp_f,
            "message": (
                f"Temperature ({celsius}°C / {temp_f}°F) exceeds fire detector "
                "threshold. Immediate danger — evacuate and contact emergency services."
            )
        }
    else:
        return {
            "level":   "normal",
            "label":   "Normal",
            "temp_c":  celsius,
            "temp_f":  temp_f,
            "message": "Temperature is within safe range."
        }


# ── HEALTH SCORE ──────────────────────────────────────────────────────────────

def calc_health_score(pm25: float, mq135: float, temp: float, hum: float) -> float:
    """
    Compute a 0–100 health risk score (higher = worse).
    Weights: PM2.5 (50%), VOC/MQ135 (30%), Humidity stress (10%), Heat stress (10%).
    """
    pm_score  = min(pm25 / 325.4, 1.0) * 100
    voc_score = min(max(mq135 - 300, 0) / 400.0, 1.0) * 100
    hum_score = min(max(abs(hum - 50) - 10, 0) / 40.0, 1.0) * 100
    tmp_score = min(max(temp - 35, 0) / 15.0, 1.0) * 100
    score = pm_score * 0.50 + voc_score * 0.30 + hum_score * 0.10 + tmp_score * 0.10
    return round(min(score, 100.0), 1)


def get_risk(health_score: float, fire_level: str) -> dict:
    """
    Return risk level dict with level, color, and advice list.
    Fire alert level takes precedence over health score.
    """
    if fire_level == "critical":
        return {
            "level":  "Critical Risk",
            "color":  "#8B0000",
            "advice": [
                "FIRE ALERT: Temperature exceeds heat detector threshold.",
                "Evacuate the area immediately.",
                "Contact emergency services (911 / local fire department)."
            ]
        }
    if fire_level == "warning":
        return {
            "level":  "High Risk",
            "color":  "#F05252",
            "advice": [
                "FIRE EARLY WARNING: Temperature is in the heat detector trigger zone.",
                "Check for smoke, open flames, or overheating equipment.",
                "Prepare to evacuate and keep exits accessible."
            ]
        }
    if health_score <= 20:
        return {
            "level":  "Low Risk",
            "color":  "#00CFA8",
            "advice": [
                "Air quality poses minimal health risk.",
                "Safe for all groups including children and elderly.",
                "Outdoor activities are encouraged."
            ]
        }
    elif health_score <= 40:
        return {
            "level":  "Mild Risk",
            "color":  "#4C9EEB",
            "advice": [
                "Generally safe conditions with minor concerns.",
                "Sensitive individuals may notice slight irritation.",
                "Consider limiting very strenuous outdoor exercise."
            ]
        }
    elif health_score <= 60:
        return {
            "level":  "Moderate Risk",
            "color":  "#F5A623",
            "advice": [
                "Noticeable health risk for sensitive groups.",
                "Children, elderly, and those with asthma should stay indoors.",
                "Healthy adults can do light activities with caution."
            ]
        }
    elif health_score <= 80:
        return {
            "level":  "High Risk",
            "color":  "#F05252",
            "advice": [
                "Significant health risk for everyone.",
                "Avoid all outdoor activities if possible.",
                "Wear N95/KN95 mask if going outside is necessary."
            ]
        }
    else:
        return {
            "level":  "Critical Risk",
            "color":  "#8B0000",
            "advice": [
                "Hazardous conditions — stay indoors immediately.",
                "Close all windows and use air purifiers.",
                "Seek medical attention if experiencing breathing difficulty."
            ]
        }