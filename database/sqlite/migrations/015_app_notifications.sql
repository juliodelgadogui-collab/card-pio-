CREATE TABLE IF NOT EXISTS app_notifications (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  tenant_id INTEGER NOT NULL,
  user_id INTEGER NOT NULL,
  mode TEXT NULL,
  type TEXT NOT NULL,
  priority TEXT NOT NULL DEFAULT 'info',
  title TEXT NOT NULL,
  message TEXT NOT NULL,
  entity_type TEXT NULL,
  entity_id TEXT NULL,
  dedupe_key TEXT NOT NULL,
  read_at TEXT NULL,
  expires_at TEXT NULL,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  UNIQUE (tenant_id,user_id,dedupe_key)
);

CREATE INDEX IF NOT EXISTS idx_app_notifications_inbox ON app_notifications(tenant_id,user_id,read_at,created_at);
CREATE INDEX IF NOT EXISTS idx_app_notifications_expiry ON app_notifications(tenant_id,expires_at);
