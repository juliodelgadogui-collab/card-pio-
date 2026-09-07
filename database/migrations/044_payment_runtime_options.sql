ALTER TABLE payment_provider_preferences ADD COLUMN card_present_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER pix_fallback_provider;
ALTER TABLE payment_provider_preferences ADD COLUMN pix_enabled TINYINT(1) NOT NULL DEFAULT 1 AFTER card_present_enabled;
ALTER TABLE payment_provider_preferences ADD COLUMN cash_enabled TINYINT(1) NOT NULL DEFAULT 1 AFTER pix_enabled;
ALTER TABLE payment_provider_preferences ADD COLUMN external_terminal_enabled TINYINT(1) NOT NULL DEFAULT 1 AFTER cash_enabled;
ALTER TABLE payment_provider_preferences ADD COLUMN external_terminal_reference_required TINYINT(1) NOT NULL DEFAULT 1 AFTER external_terminal_enabled;

CREATE TABLE external_terminal_payments (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id BIGINT UNSIGNED NOT NULL,
  unit_id BIGINT UNSIGNED NULL,
  order_id BIGINT UNSIGNED NOT NULL,
  payment_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  device_id VARCHAR(190) NULL,
  payment_method VARCHAR(20) NOT NULL,
  machine_label VARCHAR(120) NULL,
  transaction_reference VARCHAR(190) NOT NULL,
  amount_cents INT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_ext_terminal_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
  CONSTRAINT fk_ext_terminal_unit FOREIGN KEY (unit_id) REFERENCES operating_units(id) ON DELETE SET NULL,
  CONSTRAINT fk_ext_terminal_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
  CONSTRAINT fk_ext_terminal_payment FOREIGN KEY (payment_id) REFERENCES payments(id) ON DELETE CASCADE,
  CONSTRAINT fk_ext_terminal_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT,
  UNIQUE KEY uq_ext_terminal_reference (tenant_id,transaction_reference),
  UNIQUE KEY uq_ext_terminal_payment (tenant_id,payment_id),
  KEY idx_ext_terminal_order (tenant_id,order_id,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
