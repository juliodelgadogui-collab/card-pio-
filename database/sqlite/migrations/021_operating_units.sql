CREATE TABLE operating_units (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  tenant_id INTEGER NOT NULL,
  code TEXT NOT NULL,
  name TEXT NOT NULL,
  address TEXT NULL,
  active INTEGER NOT NULL DEFAULT 1,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
  UNIQUE (tenant_id,code)
);
CREATE INDEX idx_operating_unit_active ON operating_units(tenant_id,active,name);

CREATE TABLE user_unit_access (
  tenant_id INTEGER NOT NULL,
  user_id INTEGER NOT NULL,
  unit_id INTEGER NOT NULL,
  is_default INTEGER NOT NULL DEFAULT 0,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (tenant_id,user_id,unit_id),
  FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (unit_id) REFERENCES operating_units(id) ON DELETE CASCADE
);
CREATE INDEX idx_user_unit_default ON user_unit_access(tenant_id,user_id,is_default);

ALTER TABLE work_shifts ADD COLUMN unit_id INTEGER NULL REFERENCES operating_units(id) ON DELETE SET NULL;
CREATE INDEX idx_work_shift_unit ON work_shifts(tenant_id,unit_id,status,started_at);

ALTER TABLE orders ADD COLUMN unit_id INTEGER NULL REFERENCES operating_units(id) ON DELETE SET NULL;
CREATE INDEX idx_order_unit ON orders(tenant_id,unit_id,status,created_at);

ALTER TABLE cash_sessions ADD COLUMN unit_id INTEGER NULL REFERENCES operating_units(id) ON DELETE SET NULL;
CREATE INDEX idx_cash_session_unit ON cash_sessions(tenant_id,unit_id,status,opened_at);

ALTER TABLE restaurant_tables ADD COLUMN unit_id INTEGER NULL REFERENCES operating_units(id) ON DELETE SET NULL;
CREATE INDEX idx_restaurant_table_unit ON restaurant_tables(tenant_id,unit_id,status,name);
