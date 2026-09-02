ALTER TABLE orders ADD COLUMN expires_at DATETIME NULL AFTER payment_status;
CREATE INDEX idx_orders_expiry ON orders (tenant_id,payment_status,status,expires_at);
