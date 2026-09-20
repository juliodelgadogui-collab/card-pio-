CREATE TABLE IF NOT EXISTS marketplace_delivery_coupons (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(80) NOT NULL,
  name VARCHAR(160) NOT NULL,
  type ENUM('percent','fixed') NOT NULL,
  value INT UNSIGNED NOT NULL,
  min_order_cents INT UNSIGNED NOT NULL DEFAULT 0,
  max_uses_per_tenant INT UNSIGNED NULL,
  scope_type ENUM('default','tenant','city','state','plan') NOT NULL DEFAULT 'default',
  tenant_id BIGINT UNSIGNED NULL,
  city VARCHAR(120) NULL,
  state CHAR(2) NULL,
  plan_code VARCHAR(60) NULL,
  starts_at DATETIME NULL,
  ends_at DATETIME NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_by BIGINT UNSIGNED NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_marketplace_delivery_coupon_code (code),
  INDEX idx_marketplace_delivery_coupon_scope (active, scope_type, tenant_id, city, state, plan_code),
  CONSTRAINT fk_marketplace_delivery_coupon_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE SET NULL,
  CONSTRAINT fk_marketplace_delivery_coupon_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS marketplace_delivery_coupon_targets (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  marketplace_coupon_id BIGINT UNSIGNED NOT NULL,
  tenant_id BIGINT UNSIGNED NOT NULL,
  coupon_id BIGINT UNSIGNED NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_marketplace_delivery_coupon_target (marketplace_coupon_id, tenant_id),
  UNIQUE KEY uq_marketplace_delivery_coupon_row (coupon_id),
  INDEX idx_marketplace_delivery_coupon_tenant (tenant_id),
  CONSTRAINT fk_marketplace_delivery_coupon_target_parent FOREIGN KEY (marketplace_coupon_id) REFERENCES marketplace_delivery_coupons(id) ON DELETE CASCADE,
  CONSTRAINT fk_marketplace_delivery_coupon_target_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
  CONSTRAINT fk_marketplace_delivery_coupon_target_coupon FOREIGN KEY (coupon_id) REFERENCES coupons(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
