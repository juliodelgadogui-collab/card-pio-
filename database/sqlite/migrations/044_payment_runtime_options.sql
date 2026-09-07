PRAGMA foreign_keys = ON;

ALTER TABLE payment_provider_preferences ADD COLUMN card_present_enabled INTEGER NOT NULL DEFAULT 0;
ALTER TABLE payment_provider_preferences ADD COLUMN pix_enabled INTEGER NOT NULL DEFAULT 1;
ALTER TABLE payment_provider_preferences ADD COLUMN cash_enabled INTEGER NOT NULL DEFAULT 1;
ALTER TABLE payment_provider_preferences ADD COLUMN external_terminal_enabled INTEGER NOT NULL DEFAULT 1;
ALTER TABLE payment_provider_preferences ADD COLUMN external_terminal_reference_required INTEGER NOT NULL DEFAULT 1;

CREATE TABLE external_terminal_payments (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  tenant_id INTEGER NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
  unit_id INTEGER NULL REFERENCES operating_units(id) ON DELETE SET NULL,
  order_id INTEGER NOT NULL REFERENCES orders(id) ON DELETE CASCADE,
  payment_id INTEGER NOT NULL REFERENCES payments(id) ON DELETE CASCADE,
  user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
  device_id TEXT NULL,
  payment_method TEXT NOT NULL,
  machine_label TEXT NULL,
  transaction_reference TEXT NOT NULL,
  amount_cents INTEGER NOT NULL,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE (tenant_id,transaction_reference),
  UNIQUE (tenant_id,payment_id)
);
CREATE INDEX idx_ext_terminal_order ON external_terminal_payments(tenant_id,order_id,created_at);
