ALTER TABLE delivery_live_locations ADD COLUMN battery_pct INTEGER NULL;
ALTER TABLE delivery_live_locations ADD COLUMN provider TEXT NULL;
ALTER TABLE delivery_live_locations ADD COLUMN is_mock INTEGER NOT NULL DEFAULT 0;

ALTER TABLE delivery_location_history ADD COLUMN battery_pct INTEGER NULL;
ALTER TABLE delivery_location_history ADD COLUMN provider TEXT NULL;
ALTER TABLE delivery_location_history ADD COLUMN is_mock INTEGER NOT NULL DEFAULT 0;
