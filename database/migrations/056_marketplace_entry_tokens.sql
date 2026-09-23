CREATE TABLE IF NOT EXISTS marketplace_entry_tokens (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id BIGINT UNSIGNED NOT NULL,
  unit_id BIGINT UNSIGNED NOT NULL,
  nonce_hash CHAR(64) NOT NULL,
  campaign_code VARCHAR(80) NULL,
  expires_at DATETIME NOT NULL,
  used_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_marketplace_entry_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
  CONSTRAINT fk_marketplace_entry_unit FOREIGN KEY (unit_id) REFERENCES operating_units(id) ON DELETE CASCADE,
  UNIQUE KEY uq_marketplace_entry_nonce (nonce_hash),
  INDEX idx_marketplace_entry_expiry (tenant_id,unit_id,expires_at,used_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
