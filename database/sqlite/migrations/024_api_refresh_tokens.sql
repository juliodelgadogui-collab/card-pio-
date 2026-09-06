CREATE TABLE api_refresh_tokens (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  tenant_id INTEGER NOT NULL,
  user_id INTEGER NOT NULL,
  access_token_id INTEGER NULL,
  token_hash TEXT NOT NULL UNIQUE,
  device_hash TEXT NOT NULL,
  expires_at TEXT NOT NULL,
  used_at TEXT NULL,
  revoked_at TEXT NULL,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (access_token_id) REFERENCES api_tokens(id) ON DELETE SET NULL
);
CREATE INDEX idx_api_refresh_user ON api_refresh_tokens(tenant_id,user_id,device_hash,revoked_at,expires_at);
