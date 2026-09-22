CREATE TABLE IF NOT EXISTS whatsapp_communications (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id BIGINT UNSIGNED NOT NULL,
  title VARCHAR(180) NOT NULL,
  message_text TEXT NOT NULL,
  audience_type VARCHAR(40) NOT NULL DEFAULT 'manual',
  audience_json JSON NULL,
  media_type VARCHAR(20) NULL,
  media_url TEXT NULL,
  media_filename VARCHAR(255) NULL,
  media_mime VARCHAR(120) NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'draft',
  recipient_count INT NOT NULL DEFAULT 0,
  queued_count INT NOT NULL DEFAULT 0,
  sent_count INT NOT NULL DEFAULT 0,
  failed_count INT NOT NULL DEFAULT 0,
  created_by BIGINT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_wa_comm_tenant_status (tenant_id,status,created_at),
  CONSTRAINT fk_wa_comm_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS whatsapp_communication_recipients (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id BIGINT UNSIGNED NOT NULL,
  communication_id BIGINT UNSIGNED NOT NULL,
  customer_id BIGINT UNSIGNED NULL,
  recipient VARCHAR(40) NOT NULL,
  recipient_name VARCHAR(180) NULL,
  outbox_id BIGINT UNSIGNED NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'pending',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_wa_comm_recipient (communication_id,recipient),
  KEY idx_wa_comm_rec_tenant (tenant_id,communication_id,status),
  CONSTRAINT fk_wa_comm_rec_comm FOREIGN KEY (communication_id) REFERENCES whatsapp_communications(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
