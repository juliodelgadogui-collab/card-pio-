CREATE TABLE IF NOT EXISTS delivery_customer_payment_preferences (
  order_id INTEGER PRIMARY KEY,
  account_id INTEGER NOT NULL,
  tenant_id INTEGER NOT NULL,
  method TEXT NOT NULL,
  change_for_cents INTEGER NULL,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
  FOREIGN KEY (account_id) REFERENCES delivery_customer_accounts(id) ON DELETE CASCADE,
  FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_delivery_customer_payment_pref_account ON delivery_customer_payment_preferences(account_id,created_at);
