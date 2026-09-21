ALTER TABLE tickets ADD COLUMN sale_channel VARCHAR(24) NOT NULL DEFAULT 'online' AFTER qr_token;
ALTER TABLE tickets ADD COLUMN sold_by_user_id BIGINT UNSIGNED NULL AFTER sale_channel;
ALTER TABLE tickets ADD COLUMN payment_method VARCHAR(32) NULL AFTER sold_by_user_id;
CREATE INDEX idx_tickets_counter_sale ON tickets (tenant_id,event_id,sale_channel,sold_by_user_id);
