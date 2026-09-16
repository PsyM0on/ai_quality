"""
rf_predictor.py

This script implements a Random Forest regression model to predict the Air Quality Index (AQI).
Random Forest is an ensemble learning method that constructs a multitude of decision trees at
training time. For regression tasks, it outputs the mean prediction of the individual trees,
making it highly robust against overfitting and capable of capturing complex, non-linear
relationships between environmental factors (like temperature, humidity, particulate matter,
and various gases) and the resulting air quality.

This predictor forecasts AQI values for the next 1, 2, and 3 hours.
"""

import sys
import os
import json
import time
import warnings
import pandas as pd
import numpy as np
import joblib

from sklearn.ensemble import RandomForestRegressor
from sklearn.linear_model import LinearRegression
from sklearn.metrics import mean_absolute_error, r2_score
from sklearn.preprocessing import StandardScaler

# Suppress sklearn warnings for cleaner JSON output
warnings.filterwarnings("ignore")

# Ensure utils.py can be imported
sys.path.insert(0, os.path.dirname(__file__))
from utils import get_conn, get_category, get_color

MODELS_DIR = os.path.join(os.path.dirname(__file__), 'models')
RF_MODEL_PATH = os.path.join(MODELS_DIR, 'rf_model.joblib')
RF_SCALER_PATH = os.path.join(MODELS_DIR, 'rf_scaler.joblib')
RF_FEATURES_PATH = os.path.join(MODELS_DIR, 'rf_features.joblib')
RF_METRICS_PATH = os.path.join(MODELS_DIR, 'rf_metrics.json')

def main():
    try:
        conn = get_conn()
        query = """
            SELECT 
                UNIX_TIMESTAMP(`timestamp`) DIV 300 * 300 AS time_bucket,
                AVG(temp) as temp, 
                AVG(hum) as hum, 
                AVG(pm10) as pm10, 
                AVG(mq135) as mq135, 
                AVG(aqi) as aqi
            FROM telemetry_raw
            WHERE `timestamp` >= NOW() - INTERVAL 7 DAY
            GROUP BY time_bucket
            ORDER BY time_bucket ASC
        """
        df = pd.read_sql(query, conn)
        conn.close()
        
        if len(df) < 50:
            print(json.dumps({"error": "Insufficient data for modeling", "count": len(df)}))
            return
        
        # Feature Engineering
        # Convert time_bucket to datetime in UTC, then localize/convert to local time if needed
        # We will just use the local time directly from timestamp for hour and day
        dt = pd.to_datetime(df['time_bucket'], unit='s')
        df['hour_of_day'] = dt.dt.hour
        df['day_of_week'] = dt.dt.dayofweek
        
        df['rolling_avg_1h'] = df['aqi'].rolling(window=12, min_periods=1).mean()
        df['rolling_avg_3h'] = df['aqi'].rolling(window=36, min_periods=1).mean()
        df['pm10_rate'] = df['pm10'].diff().fillna(0)
        df['aqi_rate'] = df['aqi'].diff().fillna(0)
        
        # Drop rows with NaN base sensors (if any)
        df = df.dropna(subset=['temp', 'hum', 'pm10', 'mq135', 'aqi'])
        
        # Target variable: AQI value shifted forward by 12 buckets (= 1 hour ahead)
        df['target'] = df['aqi'].shift(-12)
        
        features = [
            'temp', 'hum', 'pm10', 'mq135', 
            'hour_of_day', 'day_of_week', 
            'rolling_avg_1h', 'rolling_avg_3h', 
            'pm10_rate', 'aqi_rate'
        ]
        
        train_df = df.dropna(subset=['target'])
        
        if len(train_df) < 50:
            print(json.dumps({"error": "Insufficient valid target data for training", "count": len(train_df)}))
            return
            
        X = train_df[features]
        y = train_df['target']
        
        # Model persistence and training
        model_exists = os.path.exists(RF_MODEL_PATH)
        is_fresh = False
        if model_exists:
            age_seconds = time.time() - os.path.getmtime(RF_MODEL_PATH)
            if age_seconds < 3600:
                is_fresh = True
                
        model_age_minutes = 0
        metrics_loaded = False
        
        if is_fresh:
            try:
                rf = joblib.load(RF_MODEL_PATH)
                scaler = joblib.load(RF_SCALER_PATH)
                model_age_minutes = int((time.time() - os.path.getmtime(RF_MODEL_PATH)) / 60)
                
                # 🚀 FAST INFERENCE: Load cached comparison metrics without re-predicting full dataset
                if os.path.exists(RF_METRICS_PATH):
                    with open(RF_METRICS_PATH, 'r', encoding='utf-8') as mf:
                        m_data = json.load(mf)
                        mae_rf = m_data.get('mae_rf', 0.0)
                        mae_lr = m_data.get('mae_lr', 0.0)
                        r2 = m_data.get('r2', 0.0)
                        improvement_pct = m_data.get('improvement_pct', 0.0)
                        feat_imp = m_data.get('feat_imp', [])
                        metrics_loaded = True
            except Exception:
                is_fresh = False

        if not is_fresh or not metrics_loaded:
            scaler = StandardScaler()
            X_scaled = scaler.fit_transform(X)
            
            # 🚀 MULTI-CORE PARALLELISM: n_jobs=-1 utilizes all CPU cores for training
            rf = RandomForestRegressor(n_estimators=100, random_state=42, n_jobs=-1)
            rf.fit(X_scaled, y)
            
            lr = LinearRegression(n_jobs=-1)
            lr.fit(X_scaled, y)
            
            y_pred_rf = rf.predict(X_scaled)
            mae_rf = float(mean_absolute_error(y, y_pred_rf))
            r2 = float(r2_score(y, y_pred_rf))
            mae_lr = float(mean_absolute_error(y, lr.predict(X_scaled)))
            
            improvement_pct = 0.0
            if mae_lr > 0:
                improvement_pct = round(((mae_lr - mae_rf) / mae_lr) * 100, 1)

            # Feature Importance
            importances = rf.feature_importances_
            feat_imp = [{"feature": f, "importance": float(imp)} for f, imp in zip(features, importances)]
            feat_imp.sort(key=lambda x: x['importance'], reverse=True)

            # Save models and metadata cache
            os.makedirs(MODELS_DIR, exist_ok=True)
            try:
                joblib.dump(rf, RF_MODEL_PATH)
                joblib.dump(scaler, RF_SCALER_PATH)
                joblib.dump(features, RF_FEATURES_PATH)
                
                with open(RF_METRICS_PATH, 'w', encoding='utf-8') as mf:
                    json.dump({
                        'mae_rf': mae_rf,
                        'mae_lr': mae_lr,
                        'r2': r2,
                        'improvement_pct': improvement_pct,
                        'feat_imp': feat_imp,
                        'training_samples': len(train_df)
                    }, mf)
            except Exception:
                pass
            model_age_minutes = 0

        # Prediction output for current data
        latest_row = df.iloc[-1].copy()
        
        preds = []
        
        # +1h Prediction
        c1 = latest_row[features].values.reshape(1, -1)
        pred_1 = rf.predict(scaler.transform(c1))[0]
        pred_1 = np.clip(pred_1, 0, 500)
        preds.append(pred_1)
        
        # +2h Prediction (Chaining by shifting features forward)
        f2 = latest_row.copy()
        f2['aqi'] = pred_1
        f2['rolling_avg_1h'] = (latest_row['rolling_avg_1h'] * 11 + pred_1) / 12
        f2['aqi_rate'] = pred_1 - latest_row['aqi']
        f2['hour_of_day'] = (f2['hour_of_day'] + 1) % 24
        
        c2 = f2[features].values.reshape(1, -1)
        pred_2 = rf.predict(scaler.transform(c2))[0]
        pred_2 = np.clip(pred_2, 0, 500)
        preds.append(pred_2)
        
        # +3h Prediction
        f3 = f2.copy()
        f3['aqi'] = pred_2
        f3['rolling_avg_1h'] = (f2['rolling_avg_1h'] * 11 + pred_2) / 12
        f3['aqi_rate'] = pred_2 - pred_1
        f3['hour_of_day'] = (f3['hour_of_day'] + 1) % 24
        
        c3 = f3[features].values.reshape(1, -1)
        pred_3 = rf.predict(scaler.transform(c3))[0]
        pred_3 = np.clip(pred_3, 0, 500)
        preds.append(pred_3)
        
        f1, f2_val, f3_val = int(preds[0]), int(preds[1]), int(preds[2])
        
        trend = "stable"
        if pred_3 > pred_1 + 5:
            trend = "rising"
            trend_msg = "Forecast indicates a gradual AQI increase over the next 3 hours."
        elif pred_3 < pred_1 - 5:
            trend = "falling"
            trend_msg = "Forecast indicates AQI will improve over the next 3 hours."
        else:
            trend = "stable"
            trend_msg = "Forecast indicates stable AQI over the next 3 hours."

        out = {
            "model": "RandomForest",
            "n_estimators": 100,
            "forecast_1h": f1,
            "forecast_2h": f2_val,
            "forecast_3h": f3_val,
            "category_1h": get_category(f1),
            "category_2h": get_category(f2_val),
            "category_3h": get_category(f3_val),
            "color_1h": get_color(f1),
            "color_2h": get_color(f2_val),
            "color_3h": get_color(f3_val),
            "trend": trend,
            "trend_msg": trend_msg,
            "confidence": {
                "r2_score": round(float(r2), 3),
                "mae_rf": round(float(mae_rf), 2),
                "mae_lr": round(float(mae_lr), 2),
                "improvement_pct": round(float(improvement_pct), 1)
            },
            "feature_importance": feat_imp,
            "model_info": {
                "training_samples": len(train_df),
                "features_used": len(features),
                "model_age_minutes": model_age_minutes
            }
        }
        
        print(json.dumps(out))
        
    except Exception as e:
        print(json.dumps({"error": str(e)}))

if __name__ == "__main__":
    main()
