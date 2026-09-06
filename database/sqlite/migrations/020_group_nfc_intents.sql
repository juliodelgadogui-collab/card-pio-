CREATE TABLE group_nfc_intents (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  tenant_id INTEGER NOT NULL,
  payment_group_id INTEGER NOT NULL,
  user_id INTEGER NOT NULL,
  nfc_device_id INTEGER NOT NULL,
  intent_token_hash TEXT NOT NULL,
  amount_cents INTEGER NOT NULL,
  status TEXT NOT NULL DEFAULT 'created',
  provider_transaction_code TEXT NULL,
  expires_at TEXT NOT NULL,
  verified_at TEXT NULL,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
  FOREIGN KEY (payment_group_id) REFERENCES payment_groups(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT,
  FOREIGN KEY (nfc_device_id) REFERENCES nfc_devices(id) ON DELETE RESTRICT,
  UNIQUE (intent_token_hash),
  UNIQUE (tenant_id,provider_transaction_code)
);
CREATE INDEX idx_group_nfc_group ON group_nfc_intents(tenant_id,payment_group_id,status,expires_at);
