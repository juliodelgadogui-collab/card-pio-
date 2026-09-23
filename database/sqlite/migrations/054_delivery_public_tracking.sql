CREATE TABLE IF NOT EXISTS delivery_tracking_links (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  tenant_id INTEGER NOT NULL,
  order_id INTEGER NOT NULL,
  token_hash TEXT NOT NULL,
  token_encrypted TEXT NOT NULL,
  expires_at TEXT NOT NULL,
  revoked_at TEXT NULL,
  last_accessed_at TEXT NULL,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
  FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
  UNIQUE (tenant_id,order_id),
  UNIQUE (token_hash)
);
CREATE INDEX IF NOT EXISTS idx_delivery_tracking_expiry ON delivery_tracking_links(expires_at,revoked_at);
