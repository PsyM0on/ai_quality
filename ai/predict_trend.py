import pandas as pd
import numpy as np
from sklearn.linear_model import LinearRegression
import json
import warnings
warnings.filterwarnings("ignore")

import sys, os
sys.path.insert(0, os.path.dirname(__file__))
from utils import get_conn, get_category, get_color

ONE_HOUR = 3600

# ─────────────────────────────────────────
# 1. LOAD DATA
# ─────────────────────────────────────────
try:
    conn = get_conn()
    df = pd.read_sql("""
        SELECT 
            UNIX_TIMESTAMP(`timestamp`) DIV 300 * 300 AS time_bucket,
            AVG(aqi) as aqi
        FROM telemetry_raw
        WHERE `timestamp` >= NOW() - INTERVAL 12 HOUR
        GROUP BY time_bucket
        ORDER BY time_bucket DESC
        LIMIT 144
    """, conn)
    conn.close()
except Exception as e:
    print(json.dumps({"error": str(e)}))
    exit()

df = df.dropna()

if len(df) < 2:
    print(json.dumps({"error": "Not enough data"}))
    exit()

# Map time_bucket back to datetime for the rest of the script
df['timestamp'] = pd.to_datetime(df['time_bucket'], unit='s')

# chronological order
df = df.iloc[::-1].reset_index(drop=True)

# ─────────────────────────────────────────
# 2. TIME NORMALIZATION (CRITICAL)
# ─────────────────────────────────────────
df['epoch'] = pd.to_datetime(df['timestamp']).astype('int64') / 1e9
df['t'] = df['epoch'] - df['epoch'].iloc[0]

# ─────────────────────────────────────────
# 3. TRAIN MODEL (RAW AQI)
# ─────────────────────────────────────────
X = df[['t']]
y = df['aqi']

model = LinearRegression()
model.fit(X, y)

last_aqi = float(df['aqi'].iloc[-1])

# ─────────────────────────────────────────
# 4. SAFE + RESPONSIVE SLOPE
# ─────────────────────────────────────────
slope_per_hour = float(model.coef_[0]) * ONE_HOUR

# 🔥 Remove insane slopes
if abs(slope_per_hour) > 80:
    slope_per_hour = 0

# 🔧 Allow small movements (more sensitive)
if abs(slope_per_hour) < 0.03:
    slope_per_hour = 0

# 🧲 SHORT-TERM BOOST (KEY FIX) 🧲
recent_change = 0
if len(df) >= 3:
    recent_change = df['aqi'].iloc[-1] - df['aqi'].iloc[-3]
elif len(df) == 2:
    recent_change = df['aqi'].iloc[-1] - df['aqi'].iloc[-2]

if abs(recent_change) > 5:
    slope_per_hour += recent_change * 0.5

# ─────────────────────────────────────────
# 5. PREDICTION ENGINE
# ─────────────────────────────────────────
def predict(hours):
    value = last_aqi + slope_per_hour * hours
    return int(np.clip(value, 0, 500))

pred_1h = predict(1)
pred_2h = predict(2)
pred_3h = predict(3)

# ─────────────────────────────────────────
# 6. TREND CLASSIFICATION (IMPROVED)
# ─────────────────────────────────────────
if slope_per_hour > 1.0:
    trend = "rising"
    trend_msg = "Air quality is worsening. Prepare precautions."

elif slope_per_hour < -1.0:
    trend = "falling"
    trend_msg = "Air quality is improving."

else:
    trend = "stable"

    # 🔥 UX FIX for high AQI
    if last_aqi > 200:
        trend_msg = "Air quality is consistently poor."
    else:
        trend_msg = "Air quality is stable."

# ─────────────────────────────────────────
# 7. OUTPUT
# ─────────────────────────────────────────
result = {
    "forecast_1h": pred_1h,
    "forecast_2h": pred_2h,
    "forecast_3h": pred_3h,
    "category_1h": get_category(pred_1h),
    "category_2h": get_category(pred_2h),
    "category_3h": get_category(pred_3h),
    "color_1h": get_color(pred_1h),
    "color_2h": get_color(pred_2h),
    "color_3h": get_color(pred_3h),
    "trend": trend,
    "trend_slope_per_h": round(slope_per_hour, 2),
    "trend_msg": trend_msg
}

print(json.dumps(result))
