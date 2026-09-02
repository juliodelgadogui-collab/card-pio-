-- EventMenu Premium v9.2 - paridade funcional com o sistema legado
-- Mantém nomes/arquitetura do núcleo novo e adiciona apenas recursos sem equivalente.

ALTER TABLE tenants
  ADD COLUMN support_email VARCHAR(190) NULL AFTER slug,
  ADD COLUMN phone VARCHAR(40) NULL AFTER support_email,
  ADD COLUMN custom_domain VARCHAR(190) NULL AFTER phone,
  ADD COLUMN primary_color VARCHAR(20) NULL AFTER custom_domain,
  ADD COLUMN secondary_color VARCHAR(20) NULL AFTER primary_color,
  ADD COLUMN plan_expires_at DATETIME NULL AFTER plan;

CREATE TABLE tenant_modules (
  tenant_id BIGINT UNSIGNED NOT NULL,
  module_key VARCHAR(50) NOT NULL,
  enabled TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (tenant_id,module_key),
  CONSTRAINT fk_tenant_modules_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE business_units (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id BIGINT UNSIGNED NOT NULL,
  name VARCHAR(160) NOT NULL,
  slug VARCHAR(100) NOT NULL,
  address VARCHAR(255) NULL,
  city VARCHAR(120) NULL,
  phone VARCHAR(40) NULL,
  status ENUM('active','inactive','archived') NOT NULL DEFAULT 'active',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_business_unit (tenant_id,slug),
  INDEX idx_business_units_status (tenant_id,status),
  CONSTRAINT fk_business_units_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE user_units (
  tenant_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  unit_id BIGINT UNSIGNED NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id,unit_id),
  INDEX idx_user_units_tenant (tenant_id,unit_id),
  CONSTRAINT fk_user_units_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
  CONSTRAINT fk_user_units_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_user_units_unit FOREIGN KEY (unit_id) REFERENCES business_units(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE categories ADD COLUMN unit_id BIGINT UNSIGNED NULL AFTER tenant_id;
ALTER TABLE categories ADD CONSTRAINT fk_categories_unit FOREIGN KEY (unit_id) REFERENCES business_units(id) ON DELETE SET NULL;
ALTER TABLE categories ADD INDEX idx_categories_unit (tenant_id,unit_id,active,sort_order);

ALTER TABLE products
  ADD COLUMN unit_id BIGINT UNSIGNED NULL AFTER tenant_id,
  ADD COLUMN product_type ENUM('single','combo') NOT NULL DEFAULT 'single' AFTER category_id,
  ADD COLUMN promo_price_cents INT UNSIGNED NULL AFTER price_cents,
  ADD COLUMN min_stock_qty DECIMAL(12,3) NULL AFTER stock_qty,
  ADD COLUMN preparation_minutes SMALLINT UNSIGNED NULL AFTER track_stock,
  ADD COLUMN badge VARCHAR(80) NULL AFTER preparation_minutes,
  ADD COLUMN featured TINYINT(1) NOT NULL DEFAULT 0 AFTER badge;
ALTER TABLE products ADD CONSTRAINT fk_products_unit FOREIGN KEY (unit_id) REFERENCES business_units(id) ON DELETE SET NULL;
ALTER TABLE products ADD INDEX idx_products_unit_catalog (tenant_id,unit_id,active,category_id);

ALTER TABLE customers
  ADD COLUMN status ENUM('active','inactive','blocked') NOT NULL DEFAULT 'active' AFTER document,
  ADD COLUMN internal_notes TEXT NULL AFTER points;

ALTER TABLE events ADD COLUMN unit_id BIGINT UNSIGNED NULL AFTER tenant_id;
ALTER TABLE events ADD CONSTRAINT fk_events_unit FOREIGN KEY (unit_id) REFERENCES business_units(id) ON DELETE SET NULL;
ALTER TABLE events ADD INDEX idx_events_unit (tenant_id,unit_id,status,starts_at);

ALTER TABLE restaurant_tables ADD COLUMN unit_id BIGINT UNSIGNED NULL AFTER tenant_id;
ALTER TABLE restaurant_tables ADD CONSTRAINT fk_restaurant_tables_unit FOREIGN KEY (unit_id) REFERENCES business_units(id) ON DELETE SET NULL;
ALTER TABLE restaurant_tables ADD INDEX idx_restaurant_tables_unit (tenant_id,unit_id,status);

ALTER TABLE delivery_zones ADD COLUMN unit_id BIGINT UNSIGNED NULL AFTER tenant_id;
ALTER TABLE delivery_zones ADD CONSTRAINT fk_delivery_zones_unit FOREIGN KEY (unit_id) REFERENCES business_units(id) ON DELETE SET NULL;
ALTER TABLE delivery_zones ADD INDEX idx_delivery_zones_unit (tenant_id,unit_id,active,sort_order);

ALTER TABLE orders
  ADD COLUMN unit_id BIGINT UNSIGNED NULL AFTER tenant_id,
  ADD COLUMN cancel_reason VARCHAR(500) NULL AFTER notes,
  ADD COLUMN cancelled_at DATETIME NULL AFTER cancel_reason,
  ADD COLUMN cancelled_by BIGINT UNSIGNED NULL AFTER cancelled_at;
ALTER TABLE orders ADD CONSTRAINT fk_orders_unit FOREIGN KEY (unit_id) REFERENCES business_units(id) ON DELETE SET NULL;
ALTER TABLE orders ADD CONSTRAINT fk_orders_cancelled_by FOREIGN KEY (cancelled_by) REFERENCES users(id) ON DELETE SET NULL;
ALTER TABLE orders ADD INDEX idx_orders_unit_status (tenant_id,unit_id,status,created_at);

ALTER TABLE cash_sessions ADD COLUMN unit_id BIGINT UNSIGNED NULL AFTER tenant_id;
ALTER TABLE cash_sessions ADD CONSTRAINT fk_cash_sessions_unit FOREIGN KEY (unit_id) REFERENCES business_units(id) ON DELETE SET NULL;
ALTER TABLE cash_sessions ADD INDEX idx_cash_sessions_unit (tenant_id,unit_id,status,opened_at);

CREATE TABLE user_permissions (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  permission_key VARCHAR(120) NOT NULL,
  allowed TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_user_permission (user_id,permission_key),
  INDEX idx_user_permissions_tenant (tenant_id,user_id),
  CONSTRAINT fk_user_permissions_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
  CONSTRAINT fk_user_permissions_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE notifications (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id BIGINT UNSIGNED NOT NULL,
  target_user_id BIGINT UNSIGNED NULL,
  type ENUM('info','success','warning','error','order','payment','stock','event') NOT NULL DEFAULT 'info',
  title VARCHAR(160) NOT NULL,
  message TEXT NOT NULL,
  entity_type VARCHAR(60) NULL,
  entity_id BIGINT UNSIGNED NULL,
  status ENUM('unread','read','archived') NOT NULL DEFAULT 'unread',
  read_at DATETIME NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_notifications_tenant (tenant_id,status,created_at),
  INDEX idx_notifications_user (target_user_id,status,created_at),
  CONSTRAINT fk_notifications_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
  CONSTRAINT fk_notifications_user FOREIGN KEY (target_user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE waiter_calls (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id BIGINT UNSIGNED NOT NULL,
  table_id BIGINT UNSIGNED NOT NULL,
  type ENUM('waiter','bill','help') NOT NULL DEFAULT 'waiter',
  status ENUM('open','resolved','cancelled') NOT NULL DEFAULT 'open',
  resolved_by BIGINT UNSIGNED NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  resolved_at DATETIME NULL,
  INDEX idx_waiter_calls_open (tenant_id,status,created_at),
  CONSTRAINT fk_waiter_calls_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
  CONSTRAINT fk_waiter_calls_table FOREIGN KEY (table_id) REFERENCES restaurant_tables(id) ON DELETE CASCADE,
  CONSTRAINT fk_waiter_calls_user FOREIGN KEY (resolved_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE product_option_groups (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id BIGINT UNSIGNED NOT NULL,
  product_id BIGINT UNSIGNED NOT NULL,
  name VARCHAR(120) NOT NULL,
  min_select SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  max_select SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  sort_order INT NOT NULL DEFAULT 0,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_option_groups_product (tenant_id,product_id,active,sort_order),
  CONSTRAINT fk_option_groups_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
  CONSTRAINT fk_option_groups_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE product_options (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id BIGINT UNSIGNED NOT NULL,
  group_id BIGINT UNSIGNED NOT NULL,
  name VARCHAR(120) NOT NULL,
  price_delta_cents INT NOT NULL DEFAULT 0,
  sort_order INT NOT NULL DEFAULT 0,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_product_options_group (tenant_id,group_id,active,sort_order),
  CONSTRAINT fk_product_options_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
  CONSTRAINT fk_product_options_group FOREIGN KEY (group_id) REFERENCES product_option_groups(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE order_item_options (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_item_id BIGINT UNSIGNED NOT NULL,
  option_id BIGINT UNSIGNED NULL,
  name_snapshot VARCHAR(220) NOT NULL,
  price_delta_cents INT NOT NULL DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_order_item_options_item (order_item_id),
  CONSTRAINT fk_order_item_options_item FOREIGN KEY (order_item_id) REFERENCES order_items(id) ON DELETE CASCADE,
  CONSTRAINT fk_order_item_options_option FOREIGN KEY (option_id) REFERENCES product_options(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE product_combo_items (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id BIGINT UNSIGNED NOT NULL,
  combo_product_id BIGINT UNSIGNED NOT NULL,
  component_product_id BIGINT UNSIGNED NOT NULL,
  quantity DECIMAL(12,3) NOT NULL DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_combo_component (tenant_id,combo_product_id,component_product_id),
  INDEX idx_combo_product (tenant_id,combo_product_id),
  CONSTRAINT fk_combo_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
  CONSTRAINT fk_combo_product FOREIGN KEY (combo_product_id) REFERENCES products(id) ON DELETE CASCADE,
  CONSTRAINT fk_combo_component FOREIGN KEY (component_product_id) REFERENCES products(id) ON DELETE CASCADE,
  CONSTRAINT chk_combo_not_self CHECK (combo_product_id <> component_product_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE password_reset_tokens (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  token_hash CHAR(64) NOT NULL UNIQUE,
  expires_at DATETIME NOT NULL,
  used_at DATETIME NULL,
  requested_ip VARCHAR(64) NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_password_reset_user (user_id),
  INDEX idx_password_reset_expires (expires_at),
  CONSTRAINT fk_password_reset_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE legal_documents (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  document_type ENUM('terms','privacy') NOT NULL,
  version VARCHAR(40) NOT NULL,
  content_hash CHAR(64) NOT NULL,
  title VARCHAR(180) NOT NULL,
  content LONGTEXT NOT NULL,
  published_at DATETIME NOT NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_legal_document_version (document_type,version)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE legal_acceptances (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id BIGINT UNSIGNED NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  legal_document_id BIGINT UNSIGNED NOT NULL,
  document_type VARCHAR(40) NOT NULL,
  version VARCHAR(40) NOT NULL,
  content_hash CHAR(64) NOT NULL,
  ip_address VARCHAR(64) NULL,
  user_agent VARCHAR(500) NULL,
  accepted_at DATETIME NOT NULL,
  UNIQUE KEY uq_legal_acceptance (user_id,legal_document_id),
  INDEX idx_legal_acceptance_tenant (tenant_id,user_id),
  CONSTRAINT fk_legal_acceptance_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
  CONSTRAINT fk_legal_acceptance_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_legal_acceptance_document FOREIGN KEY (legal_document_id) REFERENCES legal_documents(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE backup_history (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id BIGINT UNSIGNED NULL,
  created_by BIGINT UNSIGNED NULL,
  operation ENUM('backup','restore') NOT NULL,
  filename VARCHAR(255) NULL,
  checksum CHAR(64) NULL,
  size_bytes BIGINT UNSIGNED NULL,
  status ENUM('started','succeeded','failed') NOT NULL,
  details TEXT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_backup_history_tenant (tenant_id,created_at),
  CONSTRAINT fk_backup_history_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE SET NULL,
  CONSTRAINT fk_backup_history_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE stock_reservations (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id BIGINT UNSIGNED NOT NULL,
  order_id BIGINT UNSIGNED NOT NULL,
  product_id BIGINT UNSIGNED NOT NULL,
  quantity DECIMAL(12,3) NOT NULL,
  status ENUM('reserved','consumed','released') NOT NULL DEFAULT 'reserved',
  expires_at DATETIME NOT NULL,
  consumed_at DATETIME NULL,
  released_at DATETIME NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_stock_reservation_order_product (order_id,product_id),
  INDEX idx_stock_reservation_active (tenant_id,product_id,status,expires_at),
  CONSTRAINT fk_stock_reservation_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
  CONSTRAINT fk_stock_reservation_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
  CONSTRAINT fk_stock_reservation_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE tickets
  ADD COLUMN transferred_at DATETIME NULL AFTER checked_in_at,
  ADD COLUMN transferred_from_customer_id BIGINT UNSIGNED NULL AFTER customer_id;
ALTER TABLE tickets ADD CONSTRAINT fk_tickets_transfer_customer FOREIGN KEY (transferred_from_customer_id) REFERENCES customers(id) ON DELETE SET NULL;
