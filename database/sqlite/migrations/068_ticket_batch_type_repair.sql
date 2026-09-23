-- Repair migration for legacy SQLite installations where migration 032 was
-- recorded/applied incompletely before ticket counter sales were introduced.
-- SQLite has no portable ADD COLUMN IF NOT EXISTS, so the actual schema guard
-- is handled by the application migrator/repair path before this file is run.
-- This migration intentionally contains no destructive statements.

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
