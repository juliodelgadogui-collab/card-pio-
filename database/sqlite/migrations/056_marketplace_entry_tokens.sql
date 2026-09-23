CREATE TABLE IF NOT EXISTS marketplace_entry_tokens (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  tenant_id INTEGER NOT NULL,
  unit_id INTEGER NOT NULL,
  nonce_hash TEXT NOT NULL UNIQUE,
  campaign_code TEXT NULL,
  expires_at TEXT NOT NULL,
  used_at TEXT NULL,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
  FOREIGN KEY (unit_id) REFERENCES operating_units(id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_marketplace_entry_expiry ON marketplace_entry_tokens (tenant_id,unit_id,expires_at,used_at);
