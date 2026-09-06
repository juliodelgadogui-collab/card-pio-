CREATE TABLE order_cancellation_requests (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  tenant_id INTEGER NOT NULL,
  order_id INTEGER NOT NULL,
  unit_id INTEGER NULL,
  requested_by INTEGER NOT NULL,
  decided_by INTEGER NULL,
  reason TEXT NOT NULL,
  status TEXT NOT NULL DEFAULT 'pending',
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  decided_at TEXT NULL,
  FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
  FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
  FOREIGN KEY (unit_id) REFERENCES operating_units(id) ON DELETE SET NULL,
  FOREIGN KEY (requested_by) REFERENCES users(id) ON DELETE RESTRICT,
  FOREIGN KEY (decided_by) REFERENCES users(id) ON DELETE SET NULL
);
CREATE INDEX idx_cancel_req_pending ON order_cancellation_requests(tenant_id,unit_id,status,created_at);
CREATE INDEX idx_cancel_req_order ON order_cancellation_requests(tenant_id,order_id,status);
