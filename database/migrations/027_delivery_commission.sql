ALTER TABLE users
  ADD COLUMN delivery_commission_bps INT UNSIGNED NOT NULL DEFAULT 0,
  ADD COLUMN delivery_commission_fixed_cents INT UNSIGNED NOT NULL DEFAULT 0;
