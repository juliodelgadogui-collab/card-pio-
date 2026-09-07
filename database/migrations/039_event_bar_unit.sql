ALTER TABLE events ADD COLUMN bar_unit_id BIGINT UNSIGNED NULL;
CREATE INDEX idx_events_bar_unit ON events(tenant_id,bar_unit_id,bar_enabled);
