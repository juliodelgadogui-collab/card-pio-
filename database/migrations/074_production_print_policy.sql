CREATE TABLE IF NOT EXISTS production_print_settings (
  tenant_id BIGINT UNSIGNED NOT NULL,
  unit_id BIGINT UNSIGNED NOT NULL,
  enabled TINYINT(1) NOT NULL DEFAULT 1,
  trigger_event VARCHAR(40) NOT NULL DEFAULT 'order_confirmed',
  cashier_enabled TINYINT(1) NOT NULL DEFAULT 0,
  cashier_station_id BIGINT UNSIGNED NULL,
  enabled_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (tenant_id,unit_id),
  CONSTRAINT fk_print_settings_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
  CONSTRAINT fk_print_settings_unit FOREIGN KEY (unit_id) REFERENCES operating_units(id) ON DELETE CASCADE,
  CONSTRAINT fk_print_settings_cashier_station FOREIGN KEY (cashier_station_id) REFERENCES production_stations(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE production_print_queue
  ADD COLUMN trigger_event VARCHAR(40) NULL AFTER queue_type,
  ADD COLUMN print_scope VARCHAR(30) NOT NULL DEFAULT 'station' AFTER trigger_event,
  ADD COLUMN payload_json JSON NULL AFTER payload_hash,
  ADD COLUMN idempotency_key CHAR(64) NULL AFTER payload_json,
  ADD COLUMN reprint_of BIGINT UNSIGNED NULL AFTER idempotency_key;

ALTER TABLE production_print_queue DROP INDEX uq_prod_auto_print_order_station;
CREATE UNIQUE INDEX uq_prod_print_idempotency ON production_print_queue(idempotency_key);
CREATE INDEX idx_prod_print_policy_queue ON production_print_queue(tenant_id,unit_id,queue_type,status,trigger_event,created_at);

INSERT IGNORE INTO production_print_settings (tenant_id,unit_id,enabled,trigger_event,cashier_enabled,cashier_station_id,enabled_at)
SELECT tenant_id,id,1,'order_confirmed',0,NULL,CURRENT_TIMESTAMP
FROM operating_units
WHERE active=1;
