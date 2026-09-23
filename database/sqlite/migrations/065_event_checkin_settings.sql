CREATE TABLE IF NOT EXISTS event_checkin_settings (
  event_id INTEGER PRIMARY KEY,
  tenant_id INTEGER NOT NULL,
  enabled INTEGER NOT NULL DEFAULT 1,
  starts_at TEXT NULL,
  ends_at TEXT NULL,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
  FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_event_checkin_tenant ON event_checkin_settings(tenant_id,enabled,starts_at,ends_at);
