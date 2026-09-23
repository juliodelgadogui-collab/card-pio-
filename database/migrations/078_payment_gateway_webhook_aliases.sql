CREATE TABLE IF NOT EXISTS payment_gateway_webhook_aliases (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id BIGINT UNSIGNED NOT NULL,
  provider VARCHAR(40) NOT NULL,
  alias_slug VARCHAR(190) NOT NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_gateway_webhook_alias_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
  UNIQUE KEY uq_gateway_webhook_alias (provider, alias_slug),
  INDEX idx_gateway_webhook_alias_tenant (tenant_id, provider, active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO payment_gateway_webhook_aliases (tenant_id,provider,alias_slug,active)
SELECT g.tenant_id,g.provider,t.slug,1
FROM payment_gateways g
JOIN tenants t ON t.id=g.tenant_id
WHERE TRIM(t.slug)<>'';
