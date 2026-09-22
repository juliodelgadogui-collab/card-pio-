ALTER TABLE whatsapp_connections
  ADD COLUMN commerce_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER automation_enabled;

CREATE TABLE IF NOT EXISTS whatsapp_conversations (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id BIGINT UNSIGNED NOT NULL,
  phone VARCHAR(20) NOT NULL,
  customer_id BIGINT UNSIGNED NULL,
  draft_order_id BIGINT UNSIGNED NULL,
  active_order_id BIGINT UNSIGNED NULL,
  mode VARCHAR(20) NOT NULL DEFAULT 'auto',
  state VARCHAR(40) NOT NULL DEFAULT 'IDLE',
  context_json JSON NULL,
  assigned_user_id BIGINT UNSIGNED NULL,
  last_inbound_at TIMESTAMP NULL,
  last_outbound_at TIMESTAMP NULL,
  last_activity_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_wa_conversation_phone (tenant_id,phone),
  KEY idx_wa_conversation_mode (tenant_id,mode,last_activity_at),
  KEY idx_wa_conversation_customer (tenant_id,customer_id),
  KEY idx_wa_conversation_order (tenant_id,active_order_id),
  CONSTRAINT fk_wa_conversation_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS whatsapp_messages (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id BIGINT UNSIGNED NOT NULL,
  conversation_id BIGINT UNSIGNED NOT NULL,
  provider_message_id VARCHAR(190) NULL,
  outbox_id BIGINT UNSIGNED NULL,
  direction VARCHAR(12) NOT NULL,
  message_type VARCHAR(24) NOT NULL DEFAULT 'text',
  message_text TEXT NULL,
  payload_json JSON NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'received',
  provider_created_at TIMESTAMP NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_wa_message_provider (tenant_id,provider_message_id),
  UNIQUE KEY uq_wa_message_outbox (outbox_id),
  KEY idx_wa_message_conversation (tenant_id,conversation_id,created_at),
  KEY idx_wa_message_status (tenant_id,status,created_at),
  CONSTRAINT fk_wa_message_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
  CONSTRAINT fk_wa_message_conversation FOREIGN KEY (conversation_id) REFERENCES whatsapp_conversations(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
