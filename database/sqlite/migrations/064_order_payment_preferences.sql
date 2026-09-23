CREATE TABLE IF NOT EXISTS order_payment_preferences (
  order_id INTEGER PRIMARY KEY,
  tenant_id INTEGER NOT NULL,
  method TEXT NOT NULL,
  provider TEXT NULL,
  change_for_cents INTEGER NULL,
  source TEXT NOT NULL DEFAULT 'unknown',
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
  FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_order_payment_pref_tenant ON order_payment_preferences(tenant_id,method,updated_at);
