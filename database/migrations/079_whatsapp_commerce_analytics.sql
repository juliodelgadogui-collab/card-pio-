CREATE TABLE IF NOT EXISTS whatsapp_commerce_events (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id BIGINT UNSIGNED NOT NULL,
  conversation_id BIGINT UNSIGNED NULL,
  order_id BIGINT UNSIGNED NULL,
  event_type VARCHAR(64) NOT NULL,
  value_cents BIGINT NOT NULL DEFAULT 0,
  metadata TEXT NULL,
  idempotency_key CHAR(64) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_whatsapp_commerce_events_key (tenant_id,idempotency_key),
  KEY idx_whatsapp_commerce_events_tenant_date (tenant_id,created_at),
  KEY idx_whatsapp_commerce_events_type_date (tenant_id,event_type,created_at),
  KEY idx_whatsapp_commerce_events_order (tenant_id,order_id),
  CONSTRAINT fk_whatsapp_commerce_events_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
