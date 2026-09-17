ALTER TABLE customers ADD COLUMN phone_normalized VARCHAR(20) NULL AFTER phone;
ALTER TABLE customers ADD COLUMN default_address VARCHAR(1000) NULL AFTER document;
UPDATE customers SET phone_normalized=REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(phone,'(',''),')',''),'-',''),' ',''),'+',''),'.','') WHERE phone IS NOT NULL AND phone_normalized IS NULL;
CREATE INDEX idx_customers_phone_normalized ON customers (tenant_id,phone_normalized);
