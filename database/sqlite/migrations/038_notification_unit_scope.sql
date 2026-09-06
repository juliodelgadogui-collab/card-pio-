PRAGMA foreign_keys = ON;

ALTER TABLE app_notifications ADD COLUMN unit_id INTEGER NULL REFERENCES operating_units(id) ON DELETE CASCADE;
CREATE INDEX idx_app_notifications_unit ON app_notifications(tenant_id,unit_id,user_id,read_at,created_at);
