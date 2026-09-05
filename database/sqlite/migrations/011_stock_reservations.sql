CREATE TABLE IF NOT EXISTS stock_reservations (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  tenant_id INTEGER NOT NULL,
  order_id INTEGER NOT NULL,
  product_id INTEGER NOT NULL,
  quantity REAL NOT NULL,
  status TEXT NOT NULL DEFAULT 'reserved',
  expires_at TEXT NULL,
  created_at TEXT DEFAULT CURRENT_TIMESTAMP,
  updated_at TEXT DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
  FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
  FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
  UNIQUE (tenant_id,order_id,product_id)
);
CREATE INDEX IF NOT EXISTS idx_stock_res_expiry ON stock_reservations(tenant_id,status,expires_at);
CREATE INDEX IF NOT EXISTS idx_stock_res_product ON stock_reservations(tenant_id,product_id,status);
