CREATE TABLE IF NOT EXISTS event_schedule_items (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  tenant_id INTEGER NOT NULL,
  event_id INTEGER NOT NULL,
  starts_at DATETIME NOT NULL,
  ends_at DATETIME NULL,
  title VARCHAR(180) NOT NULL,
  description TEXT NULL,
  location VARCHAR(180) NULL,
  sort_order INTEGER NOT NULL DEFAULT 0,
  active INTEGER NOT NULL DEFAULT 1,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
  FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_event_schedule_public ON event_schedule_items(tenant_id,event_id,active,starts_at,sort_order);
