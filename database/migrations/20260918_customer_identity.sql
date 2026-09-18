ALTER TABLE customers ADD COLUMN phone_normalized VARCHAR(20) NULL AFTER phone;
ALTER TABLE customers ADD COLUMN default_address VARCHAR(1000) NULL AFTER document;
UPDATE customers SET phone_normalized=REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(phone,'(',''),')',''),'-',''),' ',''),'+',''),'.','') WHERE phone IS NOT NULL AND phone_normalized IS NULL;
UPDATE customers c JOIN (SELECT tenant_id,phone_normalized,MIN(id) keep_id FROM customers WHERE phone_normalized IS NOT NULL AND phone_normalized<>'' GROUP BY tenant_id,phone_normalized HAVING COUNT(*)>1) d ON d.tenant_id=c.tenant_id AND d.phone_normalized=c.phone_normalized SET c.phone_normalized=NULL WHERE c.id<>d.keep_id;
CREATE UNIQUE INDEX uq_customers_phone_normalized ON customers (tenant_id,phone_normalized);
