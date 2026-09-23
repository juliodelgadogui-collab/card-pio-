CREATE TABLE marketplace_campaign_assignments (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  campaign_code TEXT NOT NULL,
  name TEXT NOT NULL,
  scope_type TEXT NOT NULL,
  tenant_id INTEGER NULL,
  city TEXT NULL,
  state TEXT NULL,
  plan_code TEXT NULL,
  priority INTEGER NOT NULL DEFAULT 0,
  starts_at TEXT NULL,
  ends_at TEXT NULL,
  active INTEGER NOT NULL DEFAULT 1,
  created_by INTEGER NULL,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
  FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
);
CREATE INDEX IF NOT EXISTS idx_marketplace_campaign_match ON marketplace_campaign_assignments (active,scope_type,tenant_id,city,state,plan_code,starts_at,ends_at,priority);
CREATE INDEX IF NOT EXISTS idx_marketplace_campaign_code ON marketplace_campaign_assignments (campaign_code,active,starts_at,ends_at);
