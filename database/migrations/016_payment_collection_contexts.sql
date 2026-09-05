CREATE TABLE IF NOT EXISTS payment_collection_contexts (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id BIGINT UNSIGNED NOT NULL,
  payment_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  work_shift_id BIGINT UNSIGNED NULL,
  method ENUM('pix','card') NOT NULL,
  source VARCHAR(60) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_payment_collection_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
  CONSTRAINT fk_payment_collection_payment FOREIGN KEY (payment_id) REFERENCES payments(id) ON DELETE CASCADE,
  CONSTRAINT fk_payment_collection_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_payment_collection_shift FOREIGN KEY (work_shift_id) REFERENCES work_shifts(id) ON DELETE SET NULL,
  UNIQUE KEY uq_payment_collection_payment (tenant_id,payment_id),
  INDEX idx_payment_collection_shift (tenant_id,work_shift_id,user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
