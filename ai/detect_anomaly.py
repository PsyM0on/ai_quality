"""Per-device anomaly detection using a historical-only baseline."""
import argparse, json, math, os, sys, tempfile, time
import joblib
import numpy as np
import pandas as pd
from sklearn.ensemble import IsolationForest
from sklearn.preprocessing import StandardScaler

sys.path.insert(0, os.path.dirname(__file__))
from utils import get_conn

FEATURES = ["pm10", "mq135", "temp", "hum"]
MODEL_DIR = os.environ.get('AQ_MODEL_DIR', os.path.join(os.path.dirname(__file__), "models"))

def atomic_dump(obj, target):
    os.makedirs(os.path.dirname(target), exist_ok=True)
    fd, temporary = tempfile.mkstemp(prefix=os.path.basename(target), dir=os.path.dirname(target))
    os.close(fd)
    try:
        joblib.dump(obj, temporary)
        os.replace(temporary, target)
    finally:
        if os.path.exists(temporary): os.remove(temporary)

def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--device-id", type=int, required=True)
    args = parser.parse_args()
    if args.device_id < 1: raise ValueError("device-id must be positive")

    conn = get_conn()
    try:
        df = pd.read_sql(
            "SELECT temp, hum, mq135, pm10, timestamp FROM telemetry_raw WHERE device_id = %s ORDER BY id DESC LIMIT 501",
            conn, params=[args.device_id])
    finally:
        conn.close()
    df = df.replace([np.inf, -np.inf], np.nan).dropna(subset=FEATURES)
    df = df[(df.pm10 >= 0) & df.hum.between(0, 100)]
    if len(df) < 51:
        print(json.dumps({"error": "At least 51 valid readings are required."})); return

    latest, history = df.iloc[0], df.iloc[1:].copy()
    model_path = os.path.join(MODEL_DIR, f"iforest_device_{args.device_id}.joblib")
    scaler_path = os.path.join(MODEL_DIR, f"iforest_scaler_device_{args.device_id}.joblib")
    fresh = all(os.path.exists(p) for p in (model_path, scaler_path)) and time.time() - os.path.getmtime(model_path) < 1800
    if fresh:
        try: model, scaler = joblib.load(model_path), joblib.load(scaler_path)
        except Exception: fresh = False
    if not fresh:
        scaler = StandardScaler().fit(history[FEATURES])
        model = IsolationForest(contamination=0.05, n_estimators=100, random_state=42, n_jobs=-1)
        model.fit(scaler.transform(history[FEATURES]))
        atomic_dump(model, model_path); atomic_dump(scaler, scaler_path)

    latest_x = scaler.transform(latest[FEATURES].to_frame().T)
    prediction = int(model.predict(latest_x)[0])
    decision_score = float(model.decision_function(latest_x)[0])
    historical_scores = model.decision_function(scaler.transform(history[FEATURES]))
    percentile = float(np.mean(historical_scores >= decision_score) * 100)
    z_scores = {}
    for col in FEATURES:
        std = float(history[col].std())
        delta = float(latest[col]) - float(history[col].mean())
        # A change from a constant baseline is significant, not a zero Z-score.
        z = delta / max(std if math.isfinite(std) else 0.0, 0.1)
        z_scores[col] = round(z, 2)
    flagged = [name for name, value in z_scores.items() if abs(value) > 3.5]
    max_z = max(abs(value) for value in z_scores.values())
    # Stable temperature or integer PM values alone are not evidence of a failed sensor.
    sensor_stuck = all(df[col].head(20).nunique() == 1 for col in FEATURES)
    is_anomaly = prediction == -1 or max_z > 5 or sensor_stuck
    evidence = max(percentile, min(100.0, max_z * 15.0), 80.0 if sensor_stuck else 0.0)
    severity = "critical" if is_anomaly and evidence >= 90 else "warning" if is_anomaly else "normal"
    message = "Possible stuck sensor detected." if sensor_stuck else ("Anomaly in: " + ", ".join(flagged) + "." if flagged else ("Multivariate environmental anomaly detected." if is_anomaly else "No anomaly detected."))
    source = "combustion indicator" if latest.pm10 > 155 and latest.mq135 > 500 else "undetermined"
    source_indicator = {"type": source, "method": "unvalidated rule-based heuristic"}
    print(json.dumps({
        "device_id": args.device_id, "is_anomaly": bool(is_anomaly), "severity": severity, "message": message,
        "severity_msg": message,
        "detection_method": "historical-only Isolation Forest + Z-score",
        "isolation_forest": {"decision_score": round(decision_score, 4), "historical_extremeness_percentile": round(percentile, 1), "anomaly_score": round(evidence, 1), "prediction": prediction},
        "flagged": flagged, "z_scores": z_scores, "sensor_stuck": sensor_stuck,
        "source_indicator": source_indicator,
        "source_attribution": {"source": source, "tag": "Heuristic indicator", "confidence": None, "reasoning": "Unvalidated rule-based indicator; not source apportionment.", "recommendation": "Confirm with calibrated instruments and field inspection.", "distribution": None},
        "latest": {key: float(latest[key]) for key in FEATURES}}))

if __name__ == "__main__":
    try: main()
    except Exception as exc:
        print(json.dumps({"error": "Anomaly analysis failed"})); print(str(exc), file=sys.stderr)
