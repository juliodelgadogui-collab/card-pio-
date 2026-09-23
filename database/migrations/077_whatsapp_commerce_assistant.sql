CREATE TABLE IF NOT EXISTS whatsapp_assistant_settings (
  tenant_id BIGINT UNSIGNED NOT NULL,
  enabled TINYINT(1) NOT NULL DEFAULT 1,
  knowledge_enabled TINYINT(1) NOT NULL DEFAULT 1,
  assistant_name VARCHAR(80) NOT NULL DEFAULT 'Assistente EventMenu',
  tone VARCHAR(20) NOT NULL DEFAULT 'friendly',
  greeting_message TEXT NULL,
  unknown_behavior VARCHAR(20) NOT NULL DEFAULT 'menu',
  unknown_message TEXT NULL,
  handoff_message TEXT NULL,
  handoff_keywords TEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (tenant_id),
  CONSTRAINT fk_wa_assistant_settings_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS whatsapp_assistant_knowledge (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id BIGINT UNSIGNED NOT NULL,
  title VARCHAR(180) NOT NULL,
  answer TEXT NOT NULL,
  keywords TEXT NULL,
  enabled TINYINT(1) NOT NULL DEFAULT 1,
  sort_order INT NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_wa_assistant_knowledge_tenant (tenant_id,enabled,sort_order,id),
  CONSTRAINT fk_wa_assistant_knowledge_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
