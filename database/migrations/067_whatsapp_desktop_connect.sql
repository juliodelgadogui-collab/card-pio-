ALTER TABLE whatsapp_outbox ADD COLUMN claim_token CHAR(64) NULL AFTER locked_at;
ALTER TABLE whatsapp_outbox ADD COLUMN claimed_by_device_hash CHAR(64) NULL AFTER claim_token;
ALTER TABLE whatsapp_outbox ADD COLUMN claim_expires_at DATETIME NULL AFTER claimed_by_device_hash;
CREATE INDEX idx_whatsapp_outbox_desktop_claim ON whatsapp_outbox (tenant_id,status,available_at,claim_expires_at,id);

CREATE TABLE whatsapp_desktop_agents (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id BIGINT UNSIGNED NOT NULL,
  device_hash CHAR(64) NOT NULL,
  device_label VARCHAR(190) NULL,
  status VARCHAR(24) NOT NULL DEFAULT 'disconnected',
  phone_number VARCHAR(32) NULL,
  engine VARCHAR(32) NOT NULL DEFAULT 'baileys',
  last_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_error VARCHAR(500) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_whatsapp_desktop_agent_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
  UNIQUE KEY uq_whatsapp_desktop_agent_device (tenant_id,device_hash),
  INDEX idx_whatsapp_desktop_agent_seen (tenant_id,last_seen_at,status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
