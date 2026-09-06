ALTER TABLE events ADD COLUMN event_type VARCHAR(40) NOT NULL DEFAULT 'general';
ALTER TABLE events ADD COLUMN public_subtitle VARCHAR(255) NULL;
ALTER TABLE events ADD COLUMN capacity_total INT UNSIGNED NULL;
ALTER TABLE events ADD COLUMN primary_color VARCHAR(20) NULL;
ALTER TABLE events ADD COLUMN secondary_color VARCHAR(20) NULL;
ALTER TABLE events ADD COLUMN text_color VARCHAR(20) NULL;
ALTER TABLE events ADD COLUMN map_url VARCHAR(700) NULL;
ALTER TABLE events ADD COLUMN latitude DECIMAL(10,7) NULL;
ALTER TABLE events ADD COLUMN longitude DECIMAL(10,7) NULL;
ALTER TABLE events ADD COLUMN sales_enabled TINYINT(1) NOT NULL DEFAULT 1;
ALTER TABLE events ADD COLUMN bar_enabled TINYINT(1) NOT NULL DEFAULT 1;

CREATE TABLE IF NOT EXISTS ticket_types (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id BIGINT UNSIGNED NOT NULL,
  event_id BIGINT UNSIGNED NOT NULL,
  name VARCHAR(160) NOT NULL,
  description VARCHAR(500) NULL,
  access_area VARCHAR(160) NULL,
  capacity_total INT UNSIGNED NULL,
  sort_order INT NOT NULL DEFAULT 0,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_ticket_type_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
  CONSTRAINT fk_ticket_type_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
  INDEX idx_ticket_types_event (tenant_id,event_id,active,sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE ticket_batches ADD COLUMN ticket_type_id BIGINT UNSIGNED NULL;
ALTER TABLE ticket_batches ADD CONSTRAINT fk_ticket_batch_type FOREIGN KEY (ticket_type_id) REFERENCES ticket_types(id) ON DELETE SET NULL;
CREATE INDEX idx_ticket_batches_type ON ticket_batches(event_id,ticket_type_id,active);

CREATE TABLE IF NOT EXISTS event_promoters (
  tenant_id BIGINT UNSIGNED NOT NULL,
  event_id BIGINT UNSIGNED NOT NULL,
  promoter_id BIGINT UNSIGNED NOT NULL,
  commission_percent DECIMAL(7,3) NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (event_id,promoter_id),
  CONSTRAINT fk_event_promoter_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
  CONSTRAINT fk_event_promoter_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
  CONSTRAINT fk_event_promoter_promoter FOREIGN KEY (promoter_id) REFERENCES promoters(id) ON DELETE CASCADE,
  INDEX idx_event_promoters_active (tenant_id,event_id,active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS event_coupons (
  tenant_id BIGINT UNSIGNED NOT NULL,
  event_id BIGINT UNSIGNED NOT NULL,
  coupon_id BIGINT UNSIGNED NOT NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (event_id,coupon_id),
  CONSTRAINT fk_event_coupon_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
  CONSTRAINT fk_event_coupon_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
  CONSTRAINT fk_event_coupon_coupon FOREIGN KEY (coupon_id) REFERENCES coupons(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS event_audit_events (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id BIGINT UNSIGNED NOT NULL,
  event_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NULL,
  action VARCHAR(80) NOT NULL,
  entity_type VARCHAR(60) NULL,
  entity_id VARCHAR(120) NULL,
  metadata JSON NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_event_audit_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
  CONSTRAINT fk_event_audit_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
  CONSTRAINT fk_event_audit_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
  INDEX idx_event_audit (tenant_id,event_id,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
