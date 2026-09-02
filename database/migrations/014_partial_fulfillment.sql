ALTER TABLE orders
  ADD COLUMN fulfillment_token CHAR(40) NULL AFTER public_token,
  ADD COLUMN fulfillment_status ENUM('pending','partial','fulfilled') NOT NULL DEFAULT 'pending' AFTER payment_status,
  ADD COLUMN fulfilled_at DATETIME NULL AFTER fulfillment_status;

CREATE UNIQUE INDEX uq_orders_fulfillment_token ON orders(fulfillment_token);

ALTER TABLE order_items
  ADD COLUMN fulfilled_quantity DECIMAL(12,3) NOT NULL DEFAULT 0 AFTER quantity;

CREATE TABLE order_fulfillments (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id BIGINT UNSIGNED NOT NULL,
  order_id BIGINT UNSIGNED NOT NULL,
  order_item_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NULL,
  quantity DECIMAL(12,3) NOT NULL,
  source ENUM('counter','scan','admin') NOT NULL DEFAULT 'counter',
  idempotency_key VARCHAR(190) NOT NULL,
  notes VARCHAR(500) NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_fulfillments_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
  CONSTRAINT fk_fulfillments_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
  CONSTRAINT fk_fulfillments_item FOREIGN KEY (order_item_id) REFERENCES order_items(id) ON DELETE CASCADE,
  CONSTRAINT fk_fulfillments_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
  UNIQUE KEY uq_fulfillment_idempotency (tenant_id,idempotency_key),
  INDEX idx_fulfillment_order (tenant_id,order_id,created_at),
  INDEX idx_fulfillment_item (order_item_id,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
