ALTER TABLE coupons ADD COLUMN max_discount_cents INTEGER NULL;
ALTER TABLE marketplace_delivery_coupons ADD COLUMN max_discount_cents INTEGER NULL;
ALTER TABLE delivery_customer_accounts ADD COLUMN cpf_encrypted TEXT NULL;
ALTER TABLE delivery_customer_accounts ADD COLUMN cpf_hash TEXT NULL;
ALTER TABLE delivery_customer_accounts ADD COLUMN cpf_last2 TEXT NULL;
CREATE INDEX IF NOT EXISTS idx_delivery_customer_cpf_hash ON delivery_customer_accounts(cpf_hash);
