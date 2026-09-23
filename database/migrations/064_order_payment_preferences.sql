CREATE TABLE IF NOT EXISTS order_payment_preferences (
  order_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
  tenant_id BIGINT UNSIGNED NOT NULL,
  method VARCHAR(30) NOT NULL,
  provider VARCHAR(30) NULL,
  change_for_cents INT NULL,
  source VARCHAR(30) NOT NULL DEFAULT 'unknown',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_order_payment_pref_order FOREIGN KEY(order_id) REFERENCES orders(id) ON DELETE CASCADE,
  CONSTRAINT fk_order_payment_pref_tenant FOREIGN KEY(tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
  INDEX idx_order_payment_pref_tenant(tenant_id,method,updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
