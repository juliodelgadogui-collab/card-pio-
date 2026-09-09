ALTER TABLE delivery_live_locations
  ADD COLUMN battery_pct TINYINT UNSIGNED NULL AFTER bearing_deg,
  ADD COLUMN provider VARCHAR(32) NULL AFTER battery_pct,
  ADD COLUMN is_mock TINYINT(1) NOT NULL DEFAULT 0 AFTER provider;

ALTER TABLE delivery_location_history
  ADD COLUMN battery_pct TINYINT UNSIGNED NULL AFTER bearing_deg,
  ADD COLUMN provider VARCHAR(32) NULL AFTER battery_pct,
  ADD COLUMN is_mock TINYINT(1) NOT NULL DEFAULT 0 AFTER provider;
