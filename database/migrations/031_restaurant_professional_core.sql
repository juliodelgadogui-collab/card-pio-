ALTER TABLE products ADD COLUMN average_cost_cents INT UNSIGNED NOT NULL DEFAULT 0;
ALTER TABLE stock_movements ADD COLUMN unit_cost_cents INT UNSIGNED NULL;
ALTER TABLE stock_movements ADD COLUMN total_cost_cents BIGINT NULL;

CREATE TABLE IF NOT EXISTS production_stations (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id BIGINT UNSIGNED NOT NULL,
  code VARCHAR(80) NOT NULL,
  name VARCHAR(140) NOT NULL,
  station_type VARCHAR(40) NOT NULL DEFAULT 'kitchen',
  sla_minutes INT UNSIGNED NOT NULL DEFAULT 15,
  sort_order INT NOT NULL DEFAULT 0,
  printer_mode VARCHAR(20) NOT NULL DEFAULT 'manual',
  printer_target VARCHAR(255) NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_prod_station_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
  UNIQUE KEY uq_prod_station_code (tenant_id,code),
  INDEX idx_prod_station_active (tenant_id,active,sort_order,name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS product_production_profiles (
  tenant_id BIGINT UNSIGNED NOT NULL,
  product_id BIGINT UNSIGNED NOT NULL,
  station_id BIGINT UNSIGNED NULL,
  production_enabled TINYINT(1) NOT NULL DEFAULT 1,
  prep_minutes INT UNSIGNED NULL,
  print_mode VARCHAR(20) NOT NULL DEFAULT 'inherit',
  production_notes VARCHAR(500) NULL,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (tenant_id,product_id),
  CONSTRAINT fk_prod_profile_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
  CONSTRAINT fk_prod_profile_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
  CONSTRAINT fk_prod_profile_station FOREIGN KEY (station_id) REFERENCES production_stations(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS modifier_groups (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id BIGINT UNSIGNED NOT NULL,
  product_id BIGINT UNSIGNED NOT NULL,
  name VARCHAR(160) NOT NULL,
  required TINYINT(1) NOT NULL DEFAULT 0,
  min_select INT UNSIGNED NOT NULL DEFAULT 0,
  max_select INT UNSIGNED NOT NULL DEFAULT 1,
  sort_order INT NOT NULL DEFAULT 0,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_modifier_group_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
  CONSTRAINT fk_modifier_group_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
  INDEX idx_modifier_groups_product (tenant_id,product_id,active,sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS modifier_options (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id BIGINT UNSIGNED NOT NULL,
  group_id BIGINT UNSIGNED NOT NULL,
  name VARCHAR(160) NOT NULL,
  price_delta_cents INT NOT NULL DEFAULT 0,
  cost_cents INT UNSIGNED NOT NULL DEFAULT 0,
  inventory_product_id BIGINT UNSIGNED NULL,
  inventory_quantity DECIMAL(12,3) NOT NULL DEFAULT 0,
  station_id BIGINT UNSIGNED NULL,
  production_enabled TINYINT(1) NOT NULL DEFAULT 0,
  sort_order INT NOT NULL DEFAULT 0,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_modifier_option_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
  CONSTRAINT fk_modifier_option_group FOREIGN KEY (group_id) REFERENCES modifier_groups(id) ON DELETE CASCADE,
  CONSTRAINT fk_modifier_option_inventory FOREIGN KEY (inventory_product_id) REFERENCES products(id) ON DELETE SET NULL,
  CONSTRAINT fk_modifier_option_station FOREIGN KEY (station_id) REFERENCES production_stations(id) ON DELETE SET NULL,
  INDEX idx_modifier_options_group (tenant_id,group_id,active,sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS product_recipes (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id BIGINT UNSIGNED NOT NULL,
  product_id BIGINT UNSIGNED NOT NULL,
  ingredient_product_id BIGINT UNSIGNED NOT NULL,
  quantity DECIMAL(12,3) NOT NULL,
  waste_percent DECIMAL(7,3) NOT NULL DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_recipe_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
  CONSTRAINT fk_recipe_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
  CONSTRAINT fk_recipe_ingredient FOREIGN KEY (ingredient_product_id) REFERENCES products(id) ON DELETE RESTRICT,
  UNIQUE KEY uq_recipe_ingredient (tenant_id,product_id,ingredient_product_id),
  INDEX idx_recipe_product (tenant_id,product_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS order_item_modifiers (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id BIGINT UNSIGNED NOT NULL,
  order_id BIGINT UNSIGNED NOT NULL,
  order_item_id BIGINT UNSIGNED NOT NULL,
  modifier_group_id BIGINT UNSIGNED NULL,
  modifier_option_id BIGINT UNSIGNED NULL,
  group_name_snapshot VARCHAR(160) NOT NULL,
  option_name_snapshot VARCHAR(160) NOT NULL,
  quantity DECIMAL(12,3) NOT NULL DEFAULT 1,
  unit_price_delta_cents INT NOT NULL DEFAULT 0,
  total_delta_cents INT NOT NULL DEFAULT 0,
  station_id BIGINT UNSIGNED NULL,
  production_enabled TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_order_mod_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
  CONSTRAINT fk_order_mod_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
  CONSTRAINT fk_order_mod_item FOREIGN KEY (order_item_id) REFERENCES order_items(id) ON DELETE CASCADE,
  CONSTRAINT fk_order_mod_group FOREIGN KEY (modifier_group_id) REFERENCES modifier_groups(id) ON DELETE SET NULL,
  CONSTRAINT fk_order_mod_option FOREIGN KEY (modifier_option_id) REFERENCES modifier_options(id) ON DELETE SET NULL,
  CONSTRAINT fk_order_mod_station FOREIGN KEY (station_id) REFERENCES production_stations(id) ON DELETE SET NULL,
  INDEX idx_order_item_modifiers_order (tenant_id,order_id,order_item_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS production_jobs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id BIGINT UNSIGNED NOT NULL,
  order_id BIGINT UNSIGNED NOT NULL,
  order_item_id BIGINT UNSIGNED NULL,
  order_item_modifier_id BIGINT UNSIGNED NULL,
  station_id BIGINT UNSIGNED NOT NULL,
  kind VARCHAR(20) NOT NULL DEFAULT 'item',
  description VARCHAR(255) NOT NULL,
  quantity DECIMAL(12,3) NOT NULL DEFAULT 1,
  status VARCHAR(30) NOT NULL DEFAULT 'received',
  prep_minutes INT UNSIGNED NOT NULL DEFAULT 15,
  change_version INT UNSIGNED NOT NULL DEFAULT 1,
  received_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  started_at DATETIME NULL,
  ready_at DATETIME NULL,
  expedited_at DATETIME NULL,
  delivered_at DATETIME NULL,
  cancelled_at DATETIME NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_prod_job_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
  CONSTRAINT fk_prod_job_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
  CONSTRAINT fk_prod_job_item FOREIGN KEY (order_item_id) REFERENCES order_items(id) ON DELETE SET NULL,
  CONSTRAINT fk_prod_job_modifier FOREIGN KEY (order_item_modifier_id) REFERENCES order_item_modifiers(id) ON DELETE SET NULL,
  CONSTRAINT fk_prod_job_station FOREIGN KEY (station_id) REFERENCES production_stations(id) ON DELETE RESTRICT,
  INDEX idx_production_jobs_station (tenant_id,station_id,status,received_at),
  INDEX idx_production_jobs_order (tenant_id,order_id,status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS production_events (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id BIGINT UNSIGNED NOT NULL,
  order_id BIGINT UNSIGNED NOT NULL,
  job_id BIGINT UNSIGNED NULL,
  user_id BIGINT UNSIGNED NULL,
  event_type VARCHAR(40) NOT NULL,
  from_status VARCHAR(30) NULL,
  to_status VARCHAR(30) NULL,
  reason VARCHAR(500) NULL,
  payload_json JSON NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_prod_event_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
  CONSTRAINT fk_prod_event_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
  CONSTRAINT fk_prod_event_job FOREIGN KEY (job_id) REFERENCES production_jobs(id) ON DELETE SET NULL,
  CONSTRAINT fk_prod_event_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
  INDEX idx_production_events_order (tenant_id,order_id,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS production_prints (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id BIGINT UNSIGNED NOT NULL,
  station_id BIGINT UNSIGNED NOT NULL,
  order_id BIGINT UNSIGNED NOT NULL,
  job_id BIGINT UNSIGNED NULL,
  print_type VARCHAR(20) NOT NULL DEFAULT 'manual',
  printed_by BIGINT UNSIGNED NULL,
  reason VARCHAR(500) NULL,
  payload_hash CHAR(64) NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_prod_print_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
  CONSTRAINT fk_prod_print_station FOREIGN KEY (station_id) REFERENCES production_stations(id) ON DELETE RESTRICT,
  CONSTRAINT fk_prod_print_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
  CONSTRAINT fk_prod_print_job FOREIGN KEY (job_id) REFERENCES production_jobs(id) ON DELETE SET NULL,
  CONSTRAINT fk_prod_print_user FOREIGN KEY (printed_by) REFERENCES users(id) ON DELETE SET NULL,
  INDEX idx_production_prints_order (tenant_id,order_id,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
