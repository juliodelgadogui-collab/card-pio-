ALTER TABLE events ADD COLUMN bar_unit_id INTEGER NULL;
CREATE INDEX IF NOT EXISTS idx_events_bar_unit ON events(tenant_id,bar_unit_id,bar_enabled);
