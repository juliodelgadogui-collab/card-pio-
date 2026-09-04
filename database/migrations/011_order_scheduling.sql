ALTER TABLE orders
  ADD COLUMN scheduled_for DATETIME NULL AFTER expires_at,
  ADD COLUMN scheduled_slot_minutes SMALLINT UNSIGNED NULL AFTER scheduled_for;

CREATE INDEX idx_orders_schedule ON orders (tenant_id, channel, scheduled_for, status);
