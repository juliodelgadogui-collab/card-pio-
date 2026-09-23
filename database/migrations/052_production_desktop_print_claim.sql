ALTER TABLE production_stations
  ADD COLUMN desktop_device_id VARCHAR(190) NULL AFTER printer_target;

ALTER TABLE production_print_queue
  ADD COLUMN attempts INT UNSIGNED NOT NULL DEFAULT 0 AFTER status,
  ADD COLUMN claimed_at DATETIME NULL AFTER attempts,
  ADD COLUMN claimed_device_id VARCHAR(190) NULL AFTER claimed_at,
  ADD COLUMN last_error VARCHAR(1000) NULL AFTER claimed_device_id,
  ADD COLUMN next_attempt_at DATETIME NULL AFTER last_error,
  ADD COLUMN updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER failed_at;

CREATE INDEX idx_prod_print_claim ON production_print_queue(tenant_id,unit_id,status,next_attempt_at,created_at);
CREATE INDEX idx_prod_station_desktop ON production_stations(tenant_id,unit_id,desktop_device_id,active);
