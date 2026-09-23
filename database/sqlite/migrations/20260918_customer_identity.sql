ALTER TABLE customers ADD COLUMN phone_normalized TEXT NULL;
ALTER TABLE customers ADD COLUMN default_address TEXT NULL;
UPDATE customers SET phone_normalized=REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(phone,'(',''),')',''),'-',''),' ',''),'+',''),'.','') WHERE phone IS NOT NULL AND phone_normalized IS NULL;
UPDATE customers SET phone_normalized=NULL WHERE phone_normalized IS NOT NULL AND phone_normalized<>'' AND id NOT IN (SELECT MIN(id) FROM customers WHERE phone_normalized IS NOT NULL AND phone_normalized<>'' GROUP BY tenant_id,phone_normalized);
CREATE UNIQUE INDEX IF NOT EXISTS uq_customers_phone_normalized ON customers (tenant_id,phone_normalized);
