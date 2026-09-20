ALTER TABLE coupons
  MODIFY code VARCHAR(80) NOT NULL,
  ADD COLUMN max_discount_cents INT UNSIGNED NULL AFTER value;

ALTER TABLE marketplace_delivery_coupons
  ADD COLUMN max_discount_cents INT UNSIGNED NULL AFTER value;

ALTER TABLE delivery_customer_accounts
  ADD COLUMN cpf_encrypted LONGTEXT NULL AFTER phone,
  ADD COLUMN cpf_hash CHAR(64) NULL AFTER cpf_encrypted,
  ADD COLUMN cpf_last2 CHAR(2) NULL AFTER cpf_hash;

CREATE INDEX idx_delivery_customer_cpf_hash ON delivery_customer_accounts(cpf_hash);
