CREATE TABLE whatsapp_connections (
  tenant_id BIGINT UNSIGNED PRIMARY KEY,
  provider VARCHAR(32) NOT NULL DEFAULT 'eventmenu_connect',
  session_key CHAR(64) NOT NULL,
  status VARCHAR(24) NOT NULL DEFAULT 'disconnected',
  phone_number VARCHAR(32) NULL,
  automation_enabled TINYINT(1) NOT NULL DEFAULT 0,
  automation_enabled_at DATETIME NULL,
  last_connected_at DATETIME NULL,
  last_seen_at DATETIME NULL,
  last_error VARCHAR(500) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_whatsapp_connection_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
  UNIQUE KEY uq_whatsapp_connection_session (session_key),
  INDEX idx_whatsapp_connection_status (status,updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE whatsapp_event_templates (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id BIGINT UNSIGNED NOT NULL,
  event_type VARCHAR(48) NOT NULL,
  enabled TINYINT(1) NOT NULL DEFAULT 0,
  message_template VARCHAR(1500) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_whatsapp_template_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
  UNIQUE KEY uq_whatsapp_template_event (tenant_id,event_type),
  INDEX idx_whatsapp_template_enabled (tenant_id,enabled,event_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE whatsapp_outbox (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id BIGINT UNSIGNED NOT NULL,
  order_id BIGINT UNSIGNED NULL,
  event_type VARCHAR(48) NOT NULL,
  recipient VARCHAR(32) NOT NULL,
  message_text VARCHAR(2000) NOT NULL,
  status VARCHAR(24) NOT NULL DEFAULT 'queued',
  attempt_count INT UNSIGNED NOT NULL DEFAULT 0,
  max_attempts INT UNSIGNED NOT NULL DEFAULT 5,
  available_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  locked_at DATETIME NULL,
  sent_at DATETIME NULL,
  external_message_id VARCHAR(190) NULL,
  last_error VARCHAR(500) NULL,
  idempotency_key CHAR(64) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_whatsapp_outbox_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
  CONSTRAINT fk_whatsapp_outbox_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE SET NULL,
  UNIQUE KEY uq_whatsapp_outbox_idempotency (idempotency_key),
  INDEX idx_whatsapp_outbox_dispatch (status,available_at,id),
  INDEX idx_whatsapp_outbox_tenant (tenant_id,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;