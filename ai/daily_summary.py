import pandas as pd
import numpy as np
import mysql.connector
import json
import warnings
from datetime import date, timedelta
warnings.filterwarnings("ignore")

import sys, os
sys.path.insert(0, os.path.dirname(__file__))
from utils import get_conn, get_category, get_color, get_advice

# ── STEP 1: try timestamp-based query ────────────────
use_timestamp = False
today_df     = pd.DataFrame()
yesterday_df = pd.DataFrame()

try:
    conn = get_conn()
    query = """
        SELECT temp, hum, mq135, pm10, aqi,
               DATE(`timestamp`) AS day,
               HOUR(`timestamp`) AS hour
        FROM telemetry_raw
        WHERE `timestamp` >= NOW() - INTERVAL 48 HOUR
        ORDER BY `timestamp` ASC
    """
    df = pd.read_sql(query, conn)
    conn.close()
    df = df.dropna()
    df = df[df['pm10'] >= 0]

    if not df.empty and 'day' in df.columns:
        df['day'] = df['day'].astype(str)
        today_str     = str(date.today())
        yesterday_str = str(date.today() - timedelta(days=1))

        today_df     = df[df['day'] == today_str].copy()
        yesterday_df = df[df['day'] == yesterday_str].copy()
        use_timestamp = True

except Exception:
    pass  # fall through to id-based fallback

# ── STEP 2: fallback if timestamp failed OR today_df still empty ──
if not use_timestamp or today_df.empty:
    use_timestamp = False
    try:
        conn = get_conn()
        df2 = pd.read_sql(
            "SELECT temp, hum, mq135, pm10, aqi FROM telemetry_raw ORDER BY id DESC LIMIT 96",
            conn
        )
        conn.close()
        df2 = df2.dropna()
        df2 = df2[df2['pm10'] > 0].iloc[::-1].reset_index(drop=True)

        if df2.empty:
            print(json.dumps({"error": "No data in database yet."}))
            exit()

        half         = max(len(df2) // 2, 1)
        today_df     = df2.iloc[half:].copy()
        yesterday_df = df2.iloc[:half].copy()
    except Exception as e:
        print(json.dumps({"error": "Database error: " + str(e)}))
        exit()

# ── GUARD ─────────────────────────────────────────────
if today_df.empty:
    print(json.dumps({"error": "No data available yet."}))
    exit()

# ── AQI HELPERS ───────────────────────────────────────
# ── TODAY STATS ───────────────────────────────────────
t_avg      = round(float(today_df['aqi'].mean()), 1)
t_min      = int(today_df['aqi'].min())
t_max      = int(today_df['aqi'].max())
t_pm       = round(float(today_df['pm10'].mean()), 2)
t_temp     = round(float(today_df['temp'].mean()), 1)
t_hum      = round(float(today_df['hum'].mean()), 1)
t_readings = len(today_df)

# ── YESTERDAY STATS ───────────────────────────────────
has_yesterday = not yesterday_df.empty
if has_yesterday:
    y_avg = round(float(yesterday_df['aqi'].mean()), 1)
    y_min = int(yesterday_df['aqi'].min())
    y_max = int(yesterday_df['aqi'].max())
else:
    y_avg = y_min = y_max = None

# ── CHANGE ────────────────────────────────────────────
change = None
if has_yesterday and y_avg and y_avg > 0:
    pct       = round(((t_avg - y_avg) / y_avg) * 100, 1)
    direction = "worse" if pct > 0 else ("better" if pct < 0 else "same")
    change    = {"pct": pct, "abs": round(t_avg - y_avg, 1), "direction": direction}

# ── DOMINANT CATEGORY (PHILIPPINE CLEAN AIR ACT RA 8749) ──
bins = [0, 50, 100, 150, 200, 300, 1000]
cats = ["Good", "Fair", "Unhealthy for Sensitive Groups", "Very Unhealthy", "Acutely Unhealthy", "Emergency"]
today_df['cat'] = pd.cut(today_df['aqi'], bins=bins, labels=cats, right=True)
mode_vals    = today_df['cat'].mode()
dominant_cat = str(mode_vals.iloc[0]) if not mode_vals.empty else get_category(t_avg)

# ── SUMMARY MESSAGE ───────────────────────────────────
if change:
    d = change['direction']
    if d == "worse":
        summary = f"Air quality degraded {abs(change['pct'])}% vs prior period (RA 8749 standard)."
    elif d == "better":
        summary = f"Air quality improved {abs(change['pct'])}% vs prior period (RA 8749 standard)."
    else:
        summary = "Air quality is consistent with prior period (RA 8749 standard)."
else:
    summary = "Baseline period — monitoring under Philippine Clean Air Act (RA 8749)."

# ── HOURLY BREAKDOWN ──────────────────────────────────
hourly = []
if use_timestamp and 'hour' in today_df.columns:
    for hr in range(24):
        hr_data = today_df[today_df['hour'] == hr]
        if not hr_data.empty:
            hourly.append({"hour": int(hr), "avg_aqi": round(float(hr_data['aqi'].mean()), 1)})

# ── OUTPUT ────────────────────────────────────────────
result = {
    "today": {
        "avg_aqi":  t_avg,
        "min_aqi":  t_min,
        "max_aqi":  t_max,
        "avg_pm10": t_pm,
        "avg_temp": t_temp,
        "avg_hum":  t_hum,
        "readings": t_readings,
        "category": get_category(t_avg),
        "color":    get_color(t_avg),
        "dominant": dominant_cat,
    },
    "yesterday": {
        "avg_aqi":  y_avg,
        "min_aqi":  y_min,
        "max_aqi":  y_max,
        "category": get_category(y_avg) if y_avg is not None else None,
        "color":    get_color(y_avg)    if y_avg is not None else None,
    } if has_yesterday else None,
    "change":  change,
    "summary": summary,
    "hourly":  hourly,
    "mode":    "timestamp" if use_timestamp else "id-order",
    "date":    str(date.today())
}

print(json.dumps(result))
