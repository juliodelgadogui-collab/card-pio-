CREATE TABLE delivery_zones (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id BIGINT UNSIGNED NOT NULL,
  name VARCHAR(120) NOT NULL,
  match_type ENUM('postal_prefix','neighborhood','city') NOT NULL,
  match_value VARCHAR(120) NOT NULL,
  fee_cents INT UNSIGNED NOT NULL DEFAULT 0,
  min_order_cents INT UNSIGNED NOT NULL DEFAULT 0,
  free_above_cents INT UNSIGNED NULL,
  eta_min_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 30,
  eta_max_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 60,
  sort_order INT NOT NULL DEFAULT 0,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_delivery_zones_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
  UNIQUE KEY uq_delivery_zone_name (tenant_id,name),
  INDEX idx_delivery_zone_match (tenant_id,active,match_type,match_value,sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE orders
  ADD COLUMN delivery_zone_id BIGINT UNSIGNED NULL AFTER assigned_delivery_user_id,
  ADD COLUMN delivery_eta_min_minutes SMALLINT UNSIGNED NULL AFTER delivery_fee_cents,
  ADD COLUMN delivery_eta_max_minutes SMALLINT UNSIGNED NULL AFTER delivery_eta_min_minutes,
  ADD COLUMN delivery_started_at DATETIME NULL AFTER delivery_eta_max_minutes,
  ADD COLUMN delivered_at DATETIME NULL AFTER delivery_started_at,
  ADD CONSTRAINT fk_orders_delivery_zone FOREIGN KEY (delivery_zone_id) REFERENCES delivery_zones(id) ON DELETE SET NULL;

CREATE TABLE delivery_events (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id BIGINT UNSIGNED NOT NULL,
  order_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NULL,
  event_type ENUM('created','assigned','unassigned','out_for_delivery','delivered','cancelled','failed') NOT NULL,
  metadata JSON NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_delivery_events_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
  CONSTRAINT fk_delivery_events_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
  CONSTRAINT fk_delivery_events_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
  INDEX idx_delivery_events_order (tenant_id,order_id,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
