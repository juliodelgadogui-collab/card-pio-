ALTER TABLE fiscal_profiles ADD COLUMN street TEXT NULL;
ALTER TABLE fiscal_profiles ADD COLUMN address_number TEXT NULL;
ALTER TABLE fiscal_profiles ADD COLUMN address_complement TEXT NULL;
ALTER TABLE fiscal_profiles ADD COLUMN district TEXT NULL;
ALTER TABLE fiscal_profiles ADD COLUMN city_name TEXT NULL;
ALTER TABLE fiscal_profiles ADD COLUMN postal_code TEXT NULL;
ALTER TABLE fiscal_profiles ADD COLUMN phone TEXT NULL;
ALTER TABLE fiscal_profiles ADD COLUMN email TEXT NULL;
ALTER TABLE fiscal_profiles ADD COLUMN municipal_registration TEXT NULL;
ALTER TABLE fiscal_profiles ADD COLUMN cnae TEXT NULL;
ALTER TABLE fiscal_profiles ADD COLUMN country_code TEXT NOT NULL DEFAULT '1058';
ALTER TABLE fiscal_profiles ADD COLUMN country_name TEXT NOT NULL DEFAULT 'Brasil';

CREATE TABLE IF NOT EXISTS product_fiscal_data (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  tenant_id INTEGER NOT NULL,
  product_id INTEGER NOT NULL,
  ncm TEXT NOT NULL DEFAULT '',
  cest TEXT NULL,
  cfop TEXT NOT NULL DEFAULT '',
  commercial_unit TEXT NOT NULL DEFAULT 'UN',
  tributary_unit TEXT NOT NULL DEFAULT 'UN',
  origin TEXT NOT NULL DEFAULT '0',
  gtin TEXT NULL,
  gtin_tributary TEXT NULL,
  icms_cst TEXT NULL,
  icms_csosn TEXT NULL,
  icms_rate REAL NULL,
  pis_cst TEXT NULL,
  pis_rate REAL NULL,
  cofins_cst TEXT NULL,
  cofins_rate REAL NULL,
  ipi_cst TEXT NULL,
  ipi_rate REAL NULL,
  benefit_code TEXT NULL,
  tax_json TEXT NULL,
  enabled INTEGER NOT NULL DEFAULT 1,
  created_at TEXT DEFAULT CURRENT_TIMESTAMP,
  updated_at TEXT DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
  FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
  UNIQUE (tenant_id, product_id)
);
CREATE INDEX IF NOT EXISTS idx_product_fiscal_lookup ON product_fiscal_data(tenant_id, ncm, cfop);

ALTER TABLE customers ADD COLUMN state_registration TEXT NULL;
ALTER TABLE customers ADD COLUMN fiscal_street TEXT NULL;
ALTER TABLE customers ADD COLUMN fiscal_number TEXT NULL;
ALTER TABLE customers ADD COLUMN fiscal_complement TEXT NULL;
ALTER TABLE customers ADD COLUMN fiscal_district TEXT NULL;
ALTER TABLE customers ADD COLUMN fiscal_city_name TEXT NULL;
ALTER TABLE customers ADD COLUMN fiscal_city_code TEXT NULL;
ALTER TABLE customers ADD COLUMN fiscal_state_code TEXT NULL;
ALTER TABLE customers ADD COLUMN fiscal_postal_code TEXT NULL;
ALTER TABLE customers ADD COLUMN fiscal_country_code TEXT NULL;
ALTER TABLE customers ADD COLUMN fiscal_country_name TEXT NULL;

ALTER TABLE fiscal_documents ADD COLUMN snapshot_encrypted TEXT NULL;
ALTER TABLE fiscal_documents ADD COLUMN snapshot_hash TEXT NULL;
