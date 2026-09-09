ALTER TABLE production_stations ADD COLUMN desktop_device_id TEXT NULL;
ALTER TABLE production_print_queue ADD COLUMN attempts INTEGER NOT NULL DEFAULT 0;
ALTER TABLE production_print_queue ADD COLUMN claimed_at TEXT NULL;
ALTER TABLE production_print_queue ADD COLUMN claimed_device_id TEXT NULL;
ALTER TABLE production_print_queue ADD COLUMN last_error TEXT NULL;
ALTER TABLE production_print_queue ADD COLUMN next_attempt_at TEXT NULL;
ALTER TABLE production_print_queue ADD COLUMN updated_at TEXT DEFAULT CURRENT_TIMESTAMP;
CREATE INDEX IF NOT EXISTS idx_prod_print_claim ON production_print_queue(tenant_id,unit_id,status,next_attempt_at,created_at);
CREATE INDEX IF NOT EXISTS idx_prod_station_desktop ON production_stations(tenant_id,unit_id,desktop_device_id,active);
