ALTER TABLE orders ADD COLUMN event_id INTEGER NULL REFERENCES events(id) ON DELETE SET NULL;
CREATE INDEX IF NOT EXISTS idx_orders_event_bar ON orders(tenant_id,event_id,channel,status);
