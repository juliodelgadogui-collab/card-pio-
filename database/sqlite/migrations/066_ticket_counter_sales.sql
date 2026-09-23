ALTER TABLE tickets ADD COLUMN sale_channel TEXT NOT NULL DEFAULT 'online';
ALTER TABLE tickets ADD COLUMN sold_by_user_id INTEGER NULL;
ALTER TABLE tickets ADD COLUMN payment_method TEXT NULL;
CREATE INDEX IF NOT EXISTS idx_tickets_counter_sale ON tickets (tenant_id,event_id,sale_channel,sold_by_user_id);
