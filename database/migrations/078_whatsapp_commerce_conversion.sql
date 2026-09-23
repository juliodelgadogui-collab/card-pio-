CREATE TABLE IF NOT EXISTS whatsapp_commerce_conversion_settings (
  tenant_id BIGINT UNSIGNED NOT NULL,
  repeat_last_order_enabled TINYINT(1) NOT NULL DEFAULT 1,
  upsell_enabled TINYINT(1) NOT NULL DEFAULT 1,
  upsell_max_suggestions INT NOT NULL DEFAULT 3,
  abandoned_cart_enabled TINYINT(1) NOT NULL DEFAULT 1,
  abandoned_delay_minutes INT NOT NULL DEFAULT 60,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (tenant_id),
  CONSTRAINT fk_wa_conversion_settings_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
