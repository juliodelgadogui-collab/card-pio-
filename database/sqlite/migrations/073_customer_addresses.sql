CREATE TABLE IF NOT EXISTS customer_addresses (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  tenant_id INTEGER NOT NULL,
  customer_id INTEGER NOT NULL,
  label TEXT NOT NULL DEFAULT 'Principal',
  address_text TEXT NOT NULL,
  is_default INTEGER NOT NULL DEFAULT 0,
  source TEXT NOT NULL DEFAULT 'manual',
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
  FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_customer_addresses_lookup ON customer_addresses(tenant_id,customer_id,is_default,id);

INSERT INTO customer_addresses (tenant_id,customer_id,label,address_text,is_default,source)
SELECT c.tenant_id,c.id,'Principal',c.default_address,1,'legacy'
FROM customers c
WHERE c.default_address IS NOT NULL
  AND TRIM(c.default_address)<>''
  AND NOT EXISTS (
    SELECT 1 FROM customer_addresses ca
    WHERE ca.tenant_id=c.tenant_id AND ca.customer_id=c.id
  );
