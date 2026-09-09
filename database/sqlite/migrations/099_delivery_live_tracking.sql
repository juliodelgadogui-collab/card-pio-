CREATE TABLE delivery_live_locations (
  tenant_id INTEGER NOT NULL,
  order_id INTEGER NOT NULL,
  delivery_user_id INTEGER NOT NULL,
  latitude REAL NOT NULL,
  longitude REAL NOT NULL,
  accuracy_m REAL NULL,
  speed_mps REAL NULL,
  bearing_deg REAL NULL,
  captured_at TEXT NOT NULL,
  updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (tenant_id, order_id)
);
CREATE INDEX idx_delivery_live_user ON delivery_live_locations (tenant_id, delivery_user_id);
CREATE INDEX idx_delivery_live_updated ON delivery_live_locations (tenant_id, updated_at);

CREATE TABLE delivery_location_history (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  tenant_id INTEGER NOT NULL,
  order_id INTEGER NOT NULL,
  delivery_user_id INTEGER NOT NULL,
  latitude REAL NOT NULL,
  longitude REAL NOT NULL,
  accuracy_m REAL NULL,
  speed_mps REAL NULL,
  bearing_deg REAL NULL,
  captured_at TEXT NOT NULL,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX idx_delivery_history_order ON delivery_location_history (tenant_id, order_id, captured_at);