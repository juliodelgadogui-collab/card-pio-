ALTER TABLE payments
  ADD COLUMN checkout_expires_at DATETIME NULL AFTER verified_at,
  ADD INDEX idx_payments_checkout_expiry (tenant_id,status,checkout_expires_at);
