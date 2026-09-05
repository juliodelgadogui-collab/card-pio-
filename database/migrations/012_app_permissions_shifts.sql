CREATE TABLE IF NOT EXISTS user_permission_overrides (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    permission VARCHAR(100) NOT NULL,
    allowed TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_user_permission_override (tenant_id,user_id,permission),
    KEY idx_user_permission_user (tenant_id,user_id),
    CONSTRAINT fk_user_permission_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    CONSTRAINT fk_user_permission_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS work_shifts (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    mode VARCHAR(30) NOT NULL,
    status ENUM('open','closed') NOT NULL DEFAULT 'open',
    device_hash CHAR(64) NULL,
    opening_notes VARCHAR(500) NULL,
    closing_notes VARCHAR(500) NULL,
    started_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    ended_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY idx_work_shift_user (tenant_id,user_id,status),
    KEY idx_work_shift_mode (tenant_id,mode,status),
    CONSTRAINT fk_work_shift_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    CONSTRAINT fk_work_shift_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS work_shift_movements (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    work_shift_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    order_id BIGINT UNSIGNED NULL,
    payment_id BIGINT UNSIGNED NULL,
    type VARCHAR(40) NOT NULL,
    method VARCHAR(30) NULL,
    direction ENUM('in','out') NOT NULL,
    amount_cents BIGINT NOT NULL DEFAULT 0,
    notes VARCHAR(500) NULL,
    idempotency_key VARCHAR(160) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_work_shift_movement_key (tenant_id,idempotency_key),
    KEY idx_work_shift_movement_shift (tenant_id,work_shift_id,created_at),
    CONSTRAINT fk_work_shift_movement_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    CONSTRAINT fk_work_shift_movement_shift FOREIGN KEY (work_shift_id) REFERENCES work_shifts(id) ON DELETE CASCADE,
    CONSTRAINT fk_work_shift_movement_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_work_shift_movement_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE SET NULL,
    CONSTRAINT fk_work_shift_movement_payment FOREIGN KEY (payment_id) REFERENCES payments(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
