CREATE TABLE IF NOT EXISTS customer_points_reservations (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id BIGINT UNSIGNED NOT NULL,
  customer_id BIGINT UNSIGNED NOT NULL,
  order_id BIGINT UNSIGNED NOT NULL,
  points INT UNSIGNED NOT NULL,
  discount_cents INT UNSIGNED NOT NULL,
  status ENUM('reserved','redeemed','released','restored') NOT NULL DEFAULT 'reserved',
  redeemed_at DATETIME NULL,
  released_at DATETIME NULL,
  restored_at DATETIME NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_points_reservation_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
  CONSTRAINT fk_points_reservation_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE,
  CONSTRAINT fk_points_reservation_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
  UNIQUE KEY uq_points_reservation_order (tenant_id, order_id),
  INDEX idx_points_reservation_customer (tenant_id, customer_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
