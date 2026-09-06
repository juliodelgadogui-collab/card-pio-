CREATE TABLE payment_groups (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id BIGINT UNSIGNED NOT NULL,
  tab_id BIGINT UNSIGNED NULL,
  user_id BIGINT UNSIGNED NULL,
  provider ENUM('manual','pagbank') NOT NULL,
  method VARCHAR(30) NOT NULL,
  split_type VARCHAR(30) NOT NULL,
  idempotency_key VARCHAR(190) NOT NULL,
  amount_cents INT UNSIGNED NOT NULL,
  currency CHAR(3) NOT NULL DEFAULT 'BRL',
  status ENUM('created','pending','paid','failed','cancelled','refunded') NOT NULL DEFAULT 'created',
  provider_payment_id VARCHAR(190) NULL,
  metadata JSON NULL,
  raw_payload JSON NULL,
  verified_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_payment_group_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
  CONSTRAINT fk_payment_group_tab FOREIGN KEY (tab_id) REFERENCES tabs(id) ON DELETE RESTRICT,
  CONSTRAINT fk_payment_group_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
  UNIQUE KEY uq_payment_group_idempotency (tenant_id,idempotency_key),
  UNIQUE KEY uq_payment_group_provider_txn (tenant_id,provider,provider_payment_id),
  INDEX idx_payment_group_tab (tenant_id,tab_id,status,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE payment_group_allocations (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id BIGINT UNSIGNED NOT NULL,
  payment_group_id BIGINT UNSIGNED NOT NULL,
  order_id BIGINT UNSIGNED NOT NULL,
  amount_cents INT UNSIGNED NOT NULL,
  metadata JSON NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_payment_group_alloc_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
  CONSTRAINT fk_payment_group_alloc_group FOREIGN KEY (payment_group_id) REFERENCES payment_groups(id) ON DELETE CASCADE,
  CONSTRAINT fk_payment_group_alloc_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE RESTRICT,
  UNIQUE KEY uq_payment_group_order (payment_group_id,order_id),
  INDEX idx_payment_group_alloc_order (tenant_id,order_id,payment_group_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE payments ADD COLUMN payment_group_id BIGINT UNSIGNED NULL AFTER order_id;
ALTER TABLE payments ADD CONSTRAINT fk_payments_group FOREIGN KEY (payment_group_id) REFERENCES payment_groups(id) ON DELETE SET NULL;
CREATE INDEX idx_payments_group ON payments(tenant_id,payment_group_id,status);
