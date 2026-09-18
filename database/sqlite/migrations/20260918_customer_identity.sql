ALTER TABLE customers ADD COLUMN phone_normalized TEXT NULL;
ALTER TABLE customers ADD COLUMN default_address TEXT NULL;
UPDATE customers SET phone_normalized=REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(phone,'(',''),')',''),'-',''),' ',''),'+',''),'.','') WHERE phone IS NOT NULL AND phone_normalized IS NULL;
CREATE INDEX IF NOT EXISTS idx_customers_phone_normalized ON customers (tenant_id,phone_normalized);
