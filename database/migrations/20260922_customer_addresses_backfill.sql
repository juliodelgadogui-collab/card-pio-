INSERT INTO customer_addresses (tenant_id,customer_id,label,address_text,is_default,source)
SELECT c.tenant_id,c.id,'Principal',c.default_address,1,'legacy'
FROM customers c
WHERE c.default_address IS NOT NULL
  AND TRIM(c.default_address)<>''
  AND NOT EXISTS (
    SELECT 1 FROM customer_addresses ca
    WHERE ca.tenant_id=c.tenant_id AND ca.customer_id=c.id
  );
