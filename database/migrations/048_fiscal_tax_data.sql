ALTER TABLE fiscal_profiles
  ADD COLUMN street VARCHAR(190) NULL AFTER tax_regime,
  ADD COLUMN address_number VARCHAR(32) NULL AFTER street,
  ADD COLUMN address_complement VARCHAR(120) NULL AFTER address_number,
  ADD COLUMN district VARCHAR(120) NULL AFTER address_complement,
  ADD COLUMN city_name VARCHAR(160) NULL AFTER district,
  ADD COLUMN postal_code VARCHAR(8) NULL AFTER city_name,
  ADD COLUMN phone VARCHAR(30) NULL AFTER postal_code,
  ADD COLUMN email VARCHAR(190) NULL AFTER phone,
  ADD COLUMN municipal_registration VARCHAR(32) NULL AFTER email,
  ADD COLUMN cnae VARCHAR(12) NULL AFTER municipal_registration,
  ADD COLUMN country_code VARCHAR(8) NOT NULL DEFAULT '1058' AFTER cnae,
  ADD COLUMN country_name VARCHAR(80) NOT NULL DEFAULT 'Brasil' AFTER country_code;

CREATE TABLE IF NOT EXISTS product_fiscal_data (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id BIGINT UNSIGNED NOT NULL,
  product_id BIGINT UNSIGNED NOT NULL,
  ncm VARCHAR(8) NOT NULL DEFAULT '',
  cest VARCHAR(7) NULL,
  cfop VARCHAR(4) NOT NULL DEFAULT '',
  commercial_unit VARCHAR(6) NOT NULL DEFAULT 'UN',
  tributary_unit VARCHAR(6) NOT NULL DEFAULT 'UN',
  origin CHAR(1) NOT NULL DEFAULT '0',
  gtin VARCHAR(14) NULL,
  gtin_tributary VARCHAR(14) NULL,
  icms_cst VARCHAR(3) NULL,
  icms_csosn VARCHAR(3) NULL,
  icms_rate DECIMAL(9,4) NULL,
  pis_cst VARCHAR(2) NULL,
  pis_rate DECIMAL(9,4) NULL,
  cofins_cst VARCHAR(2) NULL,
  cofins_rate DECIMAL(9,4) NULL,
  ipi_cst VARCHAR(2) NULL,
  ipi_rate DECIMAL(9,4) NULL,
  benefit_code VARCHAR(20) NULL,
  tax_json LONGTEXT NULL,
  enabled TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_product_fiscal_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
  CONSTRAINT fk_product_fiscal_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
  UNIQUE KEY uq_product_fiscal (tenant_id, product_id),
  INDEX idx_product_fiscal_lookup (tenant_id, ncm, cfop)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE customers
  ADD COLUMN state_registration VARCHAR(32) NULL AFTER document,
  ADD COLUMN fiscal_street VARCHAR(190) NULL AFTER state_registration,
  ADD COLUMN fiscal_number VARCHAR(32) NULL AFTER fiscal_street,
  ADD COLUMN fiscal_complement VARCHAR(120) NULL AFTER fiscal_number,
  ADD COLUMN fiscal_district VARCHAR(120) NULL AFTER fiscal_complement,
  ADD COLUMN fiscal_city_name VARCHAR(160) NULL AFTER fiscal_district,
  ADD COLUMN fiscal_city_code VARCHAR(16) NULL AFTER fiscal_city_name,
  ADD COLUMN fiscal_state_code CHAR(2) NULL AFTER fiscal_city_code,
  ADD COLUMN fiscal_postal_code VARCHAR(8) NULL AFTER fiscal_state_code,
  ADD COLUMN fiscal_country_code VARCHAR(8) NULL AFTER fiscal_postal_code,
  ADD COLUMN fiscal_country_name VARCHAR(80) NULL AFTER fiscal_country_code;

ALTER TABLE fiscal_documents
  ADD COLUMN snapshot_encrypted LONGTEXT NULL AFTER cancellation_xml_encrypted,
  ADD COLUMN snapshot_hash CHAR(64) NULL AFTER snapshot_encrypted;
