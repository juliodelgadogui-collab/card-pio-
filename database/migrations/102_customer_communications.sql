CREATE TABLE IF NOT EXISTS whatsapp_settings (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  scope_key VARCHAR(80) NOT NULL,
  tenant_id BIGINT UNSIGNED NULL,
  enabled TINYINT(1) NOT NULL DEFAULT 0,
  phone_number_id VARCHAR(80) NULL,
  access_token_encrypted LONGTEXT NULL,
  graph_version VARCHAR(20) NOT NULL DEFAULT 'v25.0',
  confirmation_template VARCHAR(120) NOT NULL DEFAULT 'eventmenu_order_confirmation',
  tracking_template VARCHAR(120) NOT NULL DEFAULT 'eventmenu_delivery_tracking',
  language_code VARCHAR(20) NOT NULL DEFAULT 'pt_BR',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_whatsapp_settings_scope (scope_key),
  INDEX idx_whatsapp_settings_tenant (tenant_id),
  CONSTRAINT fk_whatsapp_settings_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS customer_communications (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id BIGINT UNSIGNED NOT NULL,
  order_id BIGINT UNSIGNED NOT NULL,
  customer_id BIGINT UNSIGNED NULL,
  event_type VARCHAR(50) NOT NULL,
  channel VARCHAR(20) NOT NULL,
  status VARCHAR(24) NOT NULL DEFAULT 'pending',
  recipient_hash CHAR(64) NOT NULL,
  recipient_hint VARCHAR(120) NULL,
  provider_message_id VARCHAR(190) NULL,
  last_error VARCHAR(500) NULL,
  sent_at DATETIME NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_customer_communication (tenant_id,order_id,event_type,channel),
  INDEX idx_customer_communication_status (status,created_at),
  CONSTRAINT fk_customer_communications_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
  CONSTRAINT fk_customer_communications_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
  CONSTRAINT fk_customer_communications_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
