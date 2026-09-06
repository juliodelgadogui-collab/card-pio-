CREATE TABLE delivery_progress (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  tenant_id INTEGER NOT NULL,
  order_id INTEGER NOT NULL,
  delivery_user_id INTEGER NOT NULL,
  unit_id INTEGER NULL,
  picked_up_at TEXT NULL,
  route_started_at TEXT NULL,
  arrived_at TEXT NULL,
  completed_at TEXT NULL,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
  FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
  FOREIGN KEY (delivery_user_id) REFERENCES users(id) ON DELETE RESTRICT,
  FOREIGN KEY (unit_id) REFERENCES operating_units(id) ON DELETE SET NULL,
  UNIQUE (tenant_id,order_id)
);
CREATE INDEX idx_delivery_progress_user ON delivery_progress(tenant_id,delivery_user_id,completed_at);
CREATE INDEX idx_delivery_progress_unit ON delivery_progress(tenant_id,unit_id,completed_at);
