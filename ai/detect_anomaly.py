"""
Anomaly Detection Module using Isolation Forest and Z-Score Explainability
========================================================================

For the capstone defense:
Algorithm Details:
- Isolation Forest (IF): An ensemble algorithm that detects anomalies using random 
  partitioning trees. It isolates observations by randomly selecting a feature and then 
  randomly selecting a split value between the maximum and minimum values of the selected feature.
- Anomaly Score: The anomaly score is based on the average path length from the root node to the 
  terminating node. Shorter paths indicate anomalies because fewer random partitions were needed 
  to isolate them.
- Contamination Parameter: Set to 0.05, representing the expected proportion of outliers in the dataset.
- Explainability Layer: While IF detects multi-dimensional anomalies, per-feature Z-scores 
  are used as a secondary explainability layer to identify which specific sensors 
  contributed to the anomaly (e.g., "Isolation Forest detects, Z-scores explain").
"""

import pandas as pd
import numpy as np
import json
import warnings
import time
import os
import sys
import joblib

from sklearn.ensemble import IsolationForest
from sklearn.preprocessing import StandardScaler

warnings.filterwarnings("ignore")

sys.path.insert(0, os.path.dirname(__file__))
from utils import get_conn

# Paths for model persistence
MODEL_DIR = os.path.join(os.path.dirname(__file__), "models")
os.makedirs(MODEL_DIR, exist_ok=True)
MODEL_PATH = os.path.join(MODEL_DIR, "iforest_model.joblib")
SCALER_PATH = os.path.join(MODEL_DIR, "iforest_scaler.joblib")

# ── DB CONNECTION
conn = get_conn()
# Increased window to 500 rows for better Isolation Forest training
query = "SELECT temp, hum, mq135, pm10, aqi FROM telemetry_raw ORDER BY id DESC LIMIT 500"
df = pd.read_sql(query, conn)
conn.close()

df = df.dropna()
df = df[df['pm10'] >= 0]

if len(df) < 10:
    print(json.dumps({"error": "Not enough data for anomaly detection. Need at least 10 readings."}))
    sys.exit()

features = ['pm10', 'mq135', 'aqi', 'temp', 'hum']

# Check model age
model_age_minutes = 0
train_model = True
if os.path.exists(MODEL_PATH) and os.path.exists(SCALER_PATH):
    file_mtime = os.path.getmtime(MODEL_PATH)
    model_age_minutes = (time.time() - file_mtime) / 60
    if model_age_minutes < 30:
        train_model = False

X = df[features].values

if train_model:
    scaler = StandardScaler()
    X_scaled = scaler.fit_transform(X)
    
    # Train Isolation Forest
    model = IsolationForest(contamination=0.05, n_estimators=100, random_state=42)
    model.fit(X_scaled)
    
    joblib.dump(model, MODEL_PATH)
    joblib.dump(scaler, SCALER_PATH)
    model_age_minutes = 0
else:
    model = joblib.load(MODEL_PATH)
    scaler = joblib.load(SCALER_PATH)
    X_scaled = scaler.transform(X)

# The most recent row is at index 0 because of DESC order
latest_scaled = X_scaled[0].reshape(1, -1)
latest = df.iloc[0]
history = df.iloc[1:]

# Get Isolation Forest prediction and score
# prediction: 1 for normal, -1 for anomaly
prediction = int(model.predict(latest_scaled)[0])

# Sklearn's decision_function returns a score: < 0 is anomaly, > 0 is normal.
# Let's map it to an anomaly score 0-100 where 100=extreme anomaly.
raw_score = float(model.decision_function(latest_scaled)[0])
# raw_score typically ranges roughly between -0.5 and 0.5.
# -0.5 -> 100, 0 -> 50, 0.5 -> 0.
mapped_score = 50 - (raw_score * 100)
iforest_score = max(0, min(100, mapped_score))

# Z-SCORE EXPLAINABILITY
z_scores = {}
anomalies_z = {}
for col in features:
    mean = history[col].mean()
    std = history[col].std()
    if std == 0:
        z = 0.0
    else:
        z = (latest[col] - mean) / std
    z_scores[col] = round(float(z), 2)
    anomalies_z[col] = abs(z) > 3.5

flagged_cols = [k for k, v in anomalies_z.items() if v]
any_z_anomaly = any(anomalies_z.values())
max_z = max(abs(v) for v in z_scores.values())

# Combined decision logic
is_anomaly = (prediction == -1) or (max_z > 5.0)

if iforest_score > 70:
    severity = "critical"
    severity_msg = "Significant spike detected. Readings are shifting rapidly."
elif iforest_score > 50:
    severity = "warning"
    severity_msg = "Minor environmental fluctuation detected."
else:
    severity = "normal"
    severity_msg = "All readings are relatively stable."

# Stuck sensor check
recent_pm_std = df['pm10'].head(10).std()
sensor_stuck = bool(recent_pm_std < 0.05)

if is_anomaly:
    sensor_names = {"pm10": "PM10", "mq135": "VOC/MQ135", "aqi": "AQI", "temp": "Temperature", "hum": "Humidity"}
    flag_labels = [sensor_names.get(c, c) for c in flagged_cols]
    if flag_labels:
        message = "Anomaly in: " + ", ".join(flag_labels) + ". " + severity_msg
    else:
        message = "Anomaly detected by AI. " + severity_msg
else:
    message = severity_msg

result = {
    "is_anomaly": is_anomaly,
    "severity": severity,
    "severity_msg": severity_msg,
    "message": message,
    "detection_method": "Isolation Forest + Z-Score",
    "isolation_forest": {
        "anomaly_score": round(iforest_score, 1),
        "prediction": prediction,
        "model_age_minutes": round(model_age_minutes, 1)
    },
    "flagged": flagged_cols,
    "z_scores": z_scores,
    "sensor_stuck": sensor_stuck,
    "latest": {
        "pm10": float(latest['pm10']),
        "mq135": float(latest['mq135']),
        "aqi": float(latest['aqi']),
        "temp": float(latest['temp']),
        "hum": float(latest['hum'])
    }
}

print(json.dumps(result))
