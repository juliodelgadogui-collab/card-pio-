ALTER TABLE delivery_customer_accounts
  ADD COLUMN terms_accepted_at DATETIME NULL,
  ADD COLUMN terms_version VARCHAR(40) NULL,
  ADD COLUMN privacy_accepted_at DATETIME NULL,
  ADD COLUMN privacy_version VARCHAR(40) NULL;
