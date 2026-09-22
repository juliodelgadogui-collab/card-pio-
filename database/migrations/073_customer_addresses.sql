CREATE TABLE IF NOT EXISTS customer_addresses (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  tenant_id BIGINT UNSIGNED NOT NULL,
  customer_id BIGINT UNSIGNED NOT NULL,
  label VARCHAR(80) NOT NULL DEFAULT 'Principal',
  address_text VARCHAR(1000) NOT NULL,
  is_default TINYINT(1) NOT NULL DEFAULT 0,
  source VARCHAR(30) NOT NULL DEFAULT 'manual',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_customer_address_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
  CONSTRAINT fk_customer_address_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE,
  INDEX idx_customer_addresses_lookup (tenant_id,customer_id,is_default,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO customer_addresses (tenant_id,customer_id,label,address_text,is_default,source)
SELECT c.tenant_id,c.id,'Principal',c.default_address,1,'legacy'
FROM customers c
WHERE c.default_address IS NOT NULL
  AND TRIM(c.default_address)<>''
  AND NOT EXISTS (
    SELECT 1 FROM customer_addresses ca
    WHERE ca.tenant_id=c.tenant_id AND ca.customer_id=c.id
  );
