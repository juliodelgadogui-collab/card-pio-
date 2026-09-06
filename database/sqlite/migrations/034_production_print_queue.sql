CREATE TABLE IF NOT EXISTS production_print_queue (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  tenant_id INTEGER NOT NULL,
  station_id INTEGER NOT NULL,
  order_id INTEGER NOT NULL,
  queue_type TEXT NOT NULL DEFAULT 'auto',
  status TEXT NOT NULL DEFAULT 'pending',
  requested_by INTEGER NULL,
  reason TEXT NULL,
  payload_hash TEXT NOT NULL,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  printed_at TEXT NULL,
  failed_at TEXT NULL,
  FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
  FOREIGN KEY (station_id) REFERENCES production_stations(id) ON DELETE RESTRICT,
  FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
  FOREIGN KEY (requested_by) REFERENCES users(id) ON DELETE SET NULL
);
CREATE INDEX IF NOT EXISTS idx_prod_print_queue_pending ON production_print_queue(tenant_id,station_id,status,created_at);
CREATE UNIQUE INDEX IF NOT EXISTS uq_prod_auto_print_order_station ON production_print_queue(tenant_id,station_id,order_id,queue_type) WHERE queue_type='auto';
