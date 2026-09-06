PRAGMA foreign_keys = ON;

ALTER TABLE products ADD COLUMN average_cost_cents INTEGER NOT NULL DEFAULT 0;
ALTER TABLE stock_movements ADD COLUMN unit_cost_cents INTEGER NULL;
ALTER TABLE stock_movements ADD COLUMN total_cost_cents INTEGER NULL;

CREATE TABLE IF NOT EXISTS production_stations (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  tenant_id INTEGER NOT NULL,
  code TEXT NOT NULL,
  name TEXT NOT NULL,
  station_type TEXT NOT NULL DEFAULT 'kitchen',
  sla_minutes INTEGER NOT NULL DEFAULT 15,
  sort_order INTEGER NOT NULL DEFAULT 0,
  printer_mode TEXT NOT NULL DEFAULT 'manual',
  printer_target TEXT NULL,
  active INTEGER NOT NULL DEFAULT 1,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
  UNIQUE (tenant_id, code)
);
CREATE INDEX IF NOT EXISTS idx_production_station_active ON production_stations(tenant_id,active,sort_order,name);

CREATE TABLE IF NOT EXISTS product_production_profiles (
  tenant_id INTEGER NOT NULL,
  product_id INTEGER NOT NULL,
  station_id INTEGER NULL,
  production_enabled INTEGER NOT NULL DEFAULT 1,
  prep_minutes INTEGER NULL,
  print_mode TEXT NOT NULL DEFAULT 'inherit',
  production_notes TEXT NULL,
  updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (tenant_id, product_id),
  FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
  FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
  FOREIGN KEY (station_id) REFERENCES production_stations(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS modifier_groups (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  tenant_id INTEGER NOT NULL,
  product_id INTEGER NOT NULL,
  name TEXT NOT NULL,
  required INTEGER NOT NULL DEFAULT 0,
  min_select INTEGER NOT NULL DEFAULT 0,
  max_select INTEGER NOT NULL DEFAULT 1,
  sort_order INTEGER NOT NULL DEFAULT 0,
  active INTEGER NOT NULL DEFAULT 1,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
  FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_modifier_groups_product ON modifier_groups(tenant_id,product_id,active,sort_order);

CREATE TABLE IF NOT EXISTS modifier_options (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  tenant_id INTEGER NOT NULL,
  group_id INTEGER NOT NULL,
  name TEXT NOT NULL,
  price_delta_cents INTEGER NOT NULL DEFAULT 0,
  cost_cents INTEGER NOT NULL DEFAULT 0,
  inventory_product_id INTEGER NULL,
  inventory_quantity REAL NOT NULL DEFAULT 0,
  station_id INTEGER NULL,
  production_enabled INTEGER NOT NULL DEFAULT 0,
  sort_order INTEGER NOT NULL DEFAULT 0,
  active INTEGER NOT NULL DEFAULT 1,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
  FOREIGN KEY (group_id) REFERENCES modifier_groups(id) ON DELETE CASCADE,
  FOREIGN KEY (inventory_product_id) REFERENCES products(id) ON DELETE SET NULL,
  FOREIGN KEY (station_id) REFERENCES production_stations(id) ON DELETE SET NULL
);
CREATE INDEX IF NOT EXISTS idx_modifier_options_group ON modifier_options(tenant_id,group_id,active,sort_order);

CREATE TABLE IF NOT EXISTS product_recipes (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  tenant_id INTEGER NOT NULL,
  product_id INTEGER NOT NULL,
  ingredient_product_id INTEGER NOT NULL,
  quantity REAL NOT NULL,
  waste_percent REAL NOT NULL DEFAULT 0,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
  FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
  FOREIGN KEY (ingredient_product_id) REFERENCES products(id) ON DELETE RESTRICT,
  UNIQUE (tenant_id,product_id,ingredient_product_id)
);
CREATE INDEX IF NOT EXISTS idx_recipe_product ON product_recipes(tenant_id,product_id);

CREATE TABLE IF NOT EXISTS order_item_modifiers (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  tenant_id INTEGER NOT NULL,
  order_id INTEGER NOT NULL,
  order_item_id INTEGER NOT NULL,
  modifier_group_id INTEGER NULL,
  modifier_option_id INTEGER NULL,
  group_name_snapshot TEXT NOT NULL,
  option_name_snapshot TEXT NOT NULL,
  quantity REAL NOT NULL DEFAULT 1,
  unit_price_delta_cents INTEGER NOT NULL DEFAULT 0,
  total_delta_cents INTEGER NOT NULL DEFAULT 0,
  station_id INTEGER NULL,
  production_enabled INTEGER NOT NULL DEFAULT 0,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
  FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
  FOREIGN KEY (order_item_id) REFERENCES order_items(id) ON DELETE CASCADE,
  FOREIGN KEY (modifier_group_id) REFERENCES modifier_groups(id) ON DELETE SET NULL,
  FOREIGN KEY (modifier_option_id) REFERENCES modifier_options(id) ON DELETE SET NULL,
  FOREIGN KEY (station_id) REFERENCES production_stations(id) ON DELETE SET NULL
);
CREATE INDEX IF NOT EXISTS idx_order_item_modifiers_order ON order_item_modifiers(tenant_id,order_id,order_item_id);

CREATE TABLE IF NOT EXISTS production_jobs (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  tenant_id INTEGER NOT NULL,
  order_id INTEGER NOT NULL,
  order_item_id INTEGER NULL,
  order_item_modifier_id INTEGER NULL,
  station_id INTEGER NOT NULL,
  kind TEXT NOT NULL DEFAULT 'item',
  description TEXT NOT NULL,
  quantity REAL NOT NULL DEFAULT 1,
  status TEXT NOT NULL DEFAULT 'received',
  prep_minutes INTEGER NOT NULL DEFAULT 15,
  change_version INTEGER NOT NULL DEFAULT 1,
  received_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  started_at TEXT NULL,
  ready_at TEXT NULL,
  expedited_at TEXT NULL,
  delivered_at TEXT NULL,
  cancelled_at TEXT NULL,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
  FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
  FOREIGN KEY (order_item_id) REFERENCES order_items(id) ON DELETE SET NULL,
  FOREIGN KEY (order_item_modifier_id) REFERENCES order_item_modifiers(id) ON DELETE SET NULL,
  FOREIGN KEY (station_id) REFERENCES production_stations(id) ON DELETE RESTRICT
);
CREATE INDEX IF NOT EXISTS idx_production_jobs_station ON production_jobs(tenant_id,station_id,status,received_at);
CREATE INDEX IF NOT EXISTS idx_production_jobs_order ON production_jobs(tenant_id,order_id,status);

CREATE TABLE IF NOT EXISTS production_events (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  tenant_id INTEGER NOT NULL,
  order_id INTEGER NOT NULL,
  job_id INTEGER NULL,
  user_id INTEGER NULL,
  event_type TEXT NOT NULL,
  from_status TEXT NULL,
  to_status TEXT NULL,
  reason TEXT NULL,
  payload_json TEXT NULL,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
  FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
  FOREIGN KEY (job_id) REFERENCES production_jobs(id) ON DELETE SET NULL,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
);
CREATE INDEX IF NOT EXISTS idx_production_events_order ON production_events(tenant_id,order_id,id);

CREATE TABLE IF NOT EXISTS production_prints (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  tenant_id INTEGER NOT NULL,
  station_id INTEGER NOT NULL,
  order_id INTEGER NOT NULL,
  job_id INTEGER NULL,
  print_type TEXT NOT NULL DEFAULT 'manual',
  printed_by INTEGER NULL,
  reason TEXT NULL,
  payload_hash TEXT NOT NULL,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
  FOREIGN KEY (station_id) REFERENCES production_stations(id) ON DELETE RESTRICT,
  FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
  FOREIGN KEY (job_id) REFERENCES production_jobs(id) ON DELETE SET NULL,
  FOREIGN KEY (printed_by) REFERENCES users(id) ON DELETE SET NULL
);
CREATE INDEX IF NOT EXISTS idx_production_prints_order ON production_prints(tenant_id,order_id,created_at);
