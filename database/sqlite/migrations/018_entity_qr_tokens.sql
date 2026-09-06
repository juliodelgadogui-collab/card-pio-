CREATE TABLE entity_qr_tokens (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  tenant_id INTEGER NOT NULL,
  entity_type TEXT NOT NULL,
  entity_id INTEGER NOT NULL,
  token_hash TEXT NOT NULL,
  label TEXT NULL,
  status TEXT NOT NULL DEFAULT 'active',
  expires_at TEXT NULL,
  last_used_at TEXT NULL,
  created_by INTEGER NULL,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
  FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
  UNIQUE (token_hash)
);
CREATE INDEX idx_entity_qr_lookup ON entity_qr_tokens(tenant_id,entity_type,entity_id,status);
CREATE INDEX idx_entity_qr_expiry ON entity_qr_tokens(tenant_id,status,expires_at);
