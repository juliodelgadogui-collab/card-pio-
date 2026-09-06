CREATE TABLE operating_units (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id BIGINT UNSIGNED NOT NULL,
  code VARCHAR(60) NOT NULL,
  name VARCHAR(160) NOT NULL,
  address VARCHAR(500) NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_operating_unit_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
  UNIQUE KEY uq_operating_unit_code (tenant_id,code),
  INDEX idx_operating_unit_active (tenant_id,active,name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE user_unit_access (
  tenant_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  unit_id BIGINT UNSIGNED NOT NULL,
  is_default TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (tenant_id,user_id,unit_id),
  CONSTRAINT fk_user_unit_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
  CONSTRAINT fk_user_unit_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_user_unit_unit FOREIGN KEY (unit_id) REFERENCES operating_units(id) ON DELETE CASCADE,
  INDEX idx_user_unit_default (tenant_id,user_id,is_default)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE work_shifts ADD COLUMN unit_id BIGINT UNSIGNED NULL AFTER user_id;
ALTER TABLE work_shifts ADD CONSTRAINT fk_work_shift_unit FOREIGN KEY (unit_id) REFERENCES operating_units(id) ON DELETE SET NULL;
CREATE INDEX idx_work_shift_unit ON work_shifts(tenant_id,unit_id,status,started_at);

ALTER TABLE orders ADD COLUMN unit_id BIGINT UNSIGNED NULL AFTER tenant_id;
ALTER TABLE orders ADD CONSTRAINT fk_order_unit FOREIGN KEY (unit_id) REFERENCES operating_units(id) ON DELETE SET NULL;
CREATE INDEX idx_order_unit ON orders(tenant_id,unit_id,status,created_at);

ALTER TABLE cash_sessions ADD COLUMN unit_id BIGINT UNSIGNED NULL AFTER user_id;
ALTER TABLE cash_sessions ADD CONSTRAINT fk_cash_session_unit FOREIGN KEY (unit_id) REFERENCES operating_units(id) ON DELETE SET NULL;
CREATE INDEX idx_cash_session_unit ON cash_sessions(tenant_id,unit_id,status,opened_at);

ALTER TABLE restaurant_tables ADD COLUMN unit_id BIGINT UNSIGNED NULL AFTER tenant_id;
ALTER TABLE restaurant_tables ADD CONSTRAINT fk_restaurant_table_unit FOREIGN KEY (unit_id) REFERENCES operating_units(id) ON DELETE SET NULL;
CREATE INDEX idx_restaurant_table_unit ON restaurant_tables(tenant_id,unit_id,status,name);
