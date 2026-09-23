CREATE TABLE IF NOT EXISTS production_print_settings (
  tenant_id INTEGER NOT NULL,
  unit_id INTEGER NOT NULL,
  enabled INTEGER NOT NULL DEFAULT 1,
  trigger_event TEXT NOT NULL DEFAULT 'order_confirmed',
  cashier_enabled INTEGER NOT NULL DEFAULT 0,
  cashier_station_id INTEGER NULL,
  enabled_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (tenant_id,unit_id),
  FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
  FOREIGN KEY (unit_id) REFERENCES operating_units(id) ON DELETE CASCADE,
  FOREIGN KEY (cashier_station_id) REFERENCES production_stations(id) ON DELETE SET NULL
);

ALTER TABLE production_print_queue ADD COLUMN trigger_event TEXT NULL;
ALTER TABLE production_print_queue ADD COLUMN print_scope TEXT NOT NULL DEFAULT 'station';
ALTER TABLE production_print_queue ADD COLUMN payload_json TEXT NULL;
ALTER TABLE production_print_queue ADD COLUMN idempotency_key TEXT NULL;
ALTER TABLE production_print_queue ADD COLUMN reprint_of INTEGER NULL;

DROP INDEX IF EXISTS uq_prod_auto_print_order_station;
CREATE UNIQUE INDEX IF NOT EXISTS uq_prod_print_idempotency ON production_print_queue(idempotency_key) WHERE idempotency_key IS NOT NULL;
CREATE INDEX IF NOT EXISTS idx_prod_print_policy_queue ON production_print_queue(tenant_id,unit_id,queue_type,status,trigger_event,created_at);

INSERT OR IGNORE INTO production_print_settings (tenant_id,unit_id,enabled,trigger_event,cashier_enabled,cashier_station_id,enabled_at)
SELECT tenant_id,id,1,'order_confirmed',0,NULL,CURRENT_TIMESTAMP
FROM operating_units
WHERE active=1;
