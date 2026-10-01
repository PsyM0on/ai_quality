-- Back up the database before applying this migration.
ALTER TABLE ai_predictions
  ADD COLUMN telemetry_id BIGINT NULL AFTER cluster_label,
  ADD COLUMN device_id INT NULL AFTER telemetry_id,
  ADD UNIQUE KEY uq_prediction_telemetry (telemetry_id),
  ADD KEY idx_prediction_device (device_id);

-- Match legacy predictions to telemetry only where timestamp matching is unambiguous.
UPDATE ai_predictions p
JOIN telemetry_raw t ON t.timestamp = p.timestamp
JOIN (
  SELECT timestamp FROM telemetry_raw GROUP BY timestamp HAVING COUNT(*) = 1
) unique_raw ON unique_raw.timestamp = t.timestamp
JOIN (
  SELECT timestamp FROM ai_predictions GROUP BY timestamp HAVING COUNT(*) = 1
) unique_pred ON unique_pred.timestamp = p.timestamp
SET p.telemetry_id = t.id, p.device_id = t.device_id
WHERE p.telemetry_id IS NULL;

ALTER TABLE ai_predictions
  ADD CONSTRAINT fk_prediction_telemetry FOREIGN KEY (telemetry_id) REFERENCES telemetry_raw(id) ON DELETE CASCADE,
  ADD CONSTRAINT fk_prediction_device FOREIGN KEY (device_id) REFERENCES devices(id);

ALTER TABLE telemetry_hourly
  MODIFY hour_timestamp TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP;

CREATE TABLE device_commands (
  id BIGINT NOT NULL AUTO_INCREMENT,
  device_id INT NOT NULL,
  command VARCHAR(40) NOT NULL,
  status ENUM('pending','delivered','acknowledged','cancelled') NOT NULL DEFAULT 'pending',
  acknowledgement VARCHAR(500) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  delivered_at TIMESTAMP NULL,
  acknowledged_at TIMESTAMP NULL,
  PRIMARY KEY (id), KEY idx_command_poll (device_id,status,id),
  CONSTRAINT fk_command_device FOREIGN KEY (device_id) REFERENCES devices(id)
);
