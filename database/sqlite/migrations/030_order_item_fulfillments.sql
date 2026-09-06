CREATE TABLE IF NOT EXISTS order_item_fulfillments (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  tenant_id INTEGER NOT NULL,
  order_id INTEGER NOT NULL,
  order_item_id INTEGER NOT NULL,
  quantity REAL NOT NULL CHECK (quantity > 0),
  fulfilled_by INTEGER NOT NULL,
  source TEXT NOT NULL DEFAULT 'qr',
  batch_key TEXT NOT NULL,
  notes TEXT NULL,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
  FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
  FOREIGN KEY (order_item_id) REFERENCES order_items(id) ON DELETE CASCADE,
  FOREIGN KEY (fulfilled_by) REFERENCES users(id) ON DELETE RESTRICT,
  UNIQUE (tenant_id, batch_key, order_item_id)
);

CREATE INDEX IF NOT EXISTS idx_order_fulfillment_order
  ON order_item_fulfillments (tenant_id, order_id, created_at);
CREATE INDEX IF NOT EXISTS idx_order_fulfillment_item
  ON order_item_fulfillments (order_item_id, created_at);
