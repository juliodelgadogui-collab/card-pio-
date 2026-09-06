PRAGMA foreign_keys = ON;

ALTER TABLE events ADD COLUMN event_type TEXT NOT NULL DEFAULT 'general';
ALTER TABLE events ADD COLUMN public_subtitle TEXT NULL;
ALTER TABLE events ADD COLUMN capacity_total INTEGER NULL;
ALTER TABLE events ADD COLUMN primary_color TEXT NULL;
ALTER TABLE events ADD COLUMN secondary_color TEXT NULL;
ALTER TABLE events ADD COLUMN text_color TEXT NULL;
ALTER TABLE events ADD COLUMN map_url TEXT NULL;
ALTER TABLE events ADD COLUMN latitude REAL NULL;
ALTER TABLE events ADD COLUMN longitude REAL NULL;
ALTER TABLE events ADD COLUMN sales_enabled INTEGER NOT NULL DEFAULT 1;
ALTER TABLE events ADD COLUMN bar_enabled INTEGER NOT NULL DEFAULT 1;

CREATE TABLE IF NOT EXISTS ticket_types (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  tenant_id INTEGER NOT NULL,
  event_id INTEGER NOT NULL,
  name TEXT NOT NULL,
  description TEXT NULL,
  access_area TEXT NULL,
  capacity_total INTEGER NULL,
  sort_order INTEGER NOT NULL DEFAULT 0,
  active INTEGER NOT NULL DEFAULT 1,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
  FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_ticket_types_event ON ticket_types(tenant_id,event_id,active,sort_order);

ALTER TABLE ticket_batches ADD COLUMN ticket_type_id INTEGER NULL REFERENCES ticket_types(id) ON DELETE SET NULL;
CREATE INDEX IF NOT EXISTS idx_ticket_batches_type ON ticket_batches(event_id,ticket_type_id,active);

CREATE TABLE IF NOT EXISTS event_promoters (
  tenant_id INTEGER NOT NULL,
  event_id INTEGER NOT NULL,
  promoter_id INTEGER NOT NULL,
  commission_percent REAL NULL,
  active INTEGER NOT NULL DEFAULT 1,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (event_id,promoter_id),
  FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
  FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
  FOREIGN KEY (promoter_id) REFERENCES promoters(id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_event_promoters_active ON event_promoters(tenant_id,event_id,active);

CREATE TABLE IF NOT EXISTS event_coupons (
  tenant_id INTEGER NOT NULL,
  event_id INTEGER NOT NULL,
  coupon_id INTEGER NOT NULL,
  active INTEGER NOT NULL DEFAULT 1,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (event_id,coupon_id),
  FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
  FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
  FOREIGN KEY (coupon_id) REFERENCES coupons(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS event_audit_events (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  tenant_id INTEGER NOT NULL,
  event_id INTEGER NOT NULL,
  user_id INTEGER NULL,
  action TEXT NOT NULL,
  entity_type TEXT NULL,
  entity_id TEXT NULL,
  metadata TEXT NULL,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
  FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
);
CREATE INDEX IF NOT EXISTS idx_event_audit ON event_audit_events(tenant_id,event_id,id);
