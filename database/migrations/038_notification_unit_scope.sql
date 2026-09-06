ALTER TABLE app_notifications ADD COLUMN unit_id BIGINT UNSIGNED NULL AFTER tenant_id;
ALTER TABLE app_notifications ADD CONSTRAINT fk_app_notification_unit FOREIGN KEY (unit_id) REFERENCES operating_units(id) ON DELETE CASCADE;
CREATE INDEX idx_app_notifications_unit ON app_notifications(tenant_id,unit_id,user_id,read_at,created_at);
