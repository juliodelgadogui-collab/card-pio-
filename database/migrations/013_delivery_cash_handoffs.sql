CREATE TABLE IF NOT EXISTS delivery_cash_handoffs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    work_shift_id BIGINT UNSIGNED NOT NULL,
    delivery_user_id BIGINT UNSIGNED NOT NULL,
    amount_cents BIGINT NOT NULL,
    status ENUM('pending','confirmed','cancelled') NOT NULL DEFAULT 'pending',
    token_hash CHAR(64) NOT NULL,
    confirmed_by BIGINT UNSIGNED NULL,
    requested_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    confirmed_at TIMESTAMP NULL,
    notes VARCHAR(500) NULL,
    UNIQUE KEY uq_delivery_handoff_token (token_hash),
    KEY idx_delivery_handoff_shift (tenant_id,work_shift_id,status),
    CONSTRAINT fk_delivery_handoff_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    CONSTRAINT fk_delivery_handoff_shift FOREIGN KEY (work_shift_id) REFERENCES work_shifts(id) ON DELETE CASCADE,
    CONSTRAINT fk_delivery_handoff_user FOREIGN KEY (delivery_user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_delivery_handoff_confirmed_by FOREIGN KEY (confirmed_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
