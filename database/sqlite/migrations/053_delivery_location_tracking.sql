CREATE TABLE IF NOT EXISTS delivery_location_events (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  tenant_id INTEGER NOT NULL,
  delivery_user_id INTEGER NOT NULL,
  shift_id INTEGER NOT NULL,
  unit_id INTEGER NULL,
  order_id INTEGER NOT NULL,
  latitude REAL NOT NULL,
  longitude REAL NOT NULL,
  accuracy_m REAL NULL,
  speed_mps REAL NULL,
  heading_degrees REAL NULL,
  provider TEXT NULL,
  device_id_hash TEXT NOT NULL,
  recorded_at TEXT NOT NULL,
  received_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
  FOREIGN KEY (delivery_user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (shift_id) REFERENCES work_shifts(id) ON DELETE CASCADE,
  FOREIGN KEY (unit_id) REFERENCES operating_units(id) ON DELETE SET NULL,
  FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_delivery_location_route ON delivery_location_events(tenant_id,order_id,recorded_at);
CREATE INDEX IF NOT EXISTS idx_delivery_location_user ON delivery_location_events(tenant_id,unit_id,delivery_user_id,recorded_at);

CREATE TABLE IF NOT EXISTS delivery_live_locations (
  tenant_id INTEGER NOT NULL,
  delivery_user_id INTEGER NOT NULL,
  shift_id INTEGER NOT NULL,
  unit_id INTEGER NULL,
  order_id INTEGER NOT NULL,
  latitude REAL NOT NULL,
  longitude REAL NOT NULL,
  accuracy_m REAL NULL,
  speed_mps REAL NULL,
  heading_degrees REAL NULL,
  recorded_at TEXT NOT NULL,
  received_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  device_id_hash TEXT NOT NULL,
  PRIMARY KEY (tenant_id,delivery_user_id),
  FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
  FOREIGN KEY (delivery_user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (shift_id) REFERENCES work_shifts(id) ON DELETE CASCADE,
  FOREIGN KEY (unit_id) REFERENCES operating_units(id) ON DELETE SET NULL,
  FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_delivery_live_unit ON delivery_live_locations(tenant_id,unit_id,received_at);
CREATE INDEX IF NOT EXISTS idx_delivery_live_order ON delivery_live_locations(tenant_id,order_id,received_at);
