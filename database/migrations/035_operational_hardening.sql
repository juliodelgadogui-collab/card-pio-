INSERT INTO operating_units (tenant_id,code,name,address,active)
SELECT t.id,'principal','Principal',NULL,1
FROM tenants t
WHERE NOT EXISTS (SELECT 1 FROM operating_units ou WHERE ou.tenant_id=t.id);

UPDATE orders o SET unit_id=(SELECT MIN(ou.id) FROM operating_units ou WHERE ou.tenant_id=o.tenant_id) WHERE o.unit_id IS NULL;
UPDATE cash_sessions cs SET unit_id=(SELECT MIN(ou.id) FROM operating_units ou WHERE ou.tenant_id=cs.tenant_id) WHERE cs.unit_id IS NULL;
UPDATE restaurant_tables rt SET unit_id=(SELECT MIN(ou.id) FROM operating_units ou WHERE ou.tenant_id=rt.tenant_id) WHERE rt.unit_id IS NULL;
UPDATE work_shifts ws SET unit_id=(SELECT MIN(ou.id) FROM operating_units ou WHERE ou.tenant_id=ws.tenant_id) WHERE ws.unit_id IS NULL;

ALTER TABLE products ADD COLUMN stock_unit VARCHAR(20) NOT NULL DEFAULT 'un';
ALTER TABLE products ADD COLUMN purchase_unit VARCHAR(20) NOT NULL DEFAULT 'un';
ALTER TABLE products ADD COLUMN purchase_factor DECIMAL(14,6) NOT NULL DEFAULT 1;
ALTER TABLE products ADD COLUMN min_stock_qty DECIMAL(14,3) NOT NULL DEFAULT 0;

CREATE TABLE unit_inventory (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id BIGINT UNSIGNED NOT NULL,
  unit_id BIGINT UNSIGNED NOT NULL,
  product_id BIGINT UNSIGNED NOT NULL,
  stock_qty DECIMAL(14,3) NOT NULL DEFAULT 0,
  average_cost_cents BIGINT NOT NULL DEFAULT 0,
  min_stock_qty DECIMAL(14,3) NOT NULL DEFAULT 0,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_unit_inventory_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
  CONSTRAINT fk_unit_inventory_unit FOREIGN KEY (unit_id) REFERENCES operating_units(id) ON DELETE CASCADE,
  CONSTRAINT fk_unit_inventory_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
  UNIQUE KEY uq_unit_inventory (tenant_id,unit_id,product_id),
  INDEX idx_unit_inventory_lookup (tenant_id,unit_id,product_id),
  INDEX idx_unit_inventory_low (tenant_id,unit_id,stock_qty,min_stock_qty)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO unit_inventory (tenant_id,unit_id,product_id,stock_qty,average_cost_cents,min_stock_qty)
SELECT p.tenant_id,(SELECT MIN(ou.id) FROM operating_units ou WHERE ou.tenant_id=p.tenant_id),p.id,p.stock_qty,p.average_cost_cents,p.min_stock_qty
FROM products p
WHERE (SELECT MIN(ou.id) FROM operating_units ou WHERE ou.tenant_id=p.tenant_id) IS NOT NULL;

ALTER TABLE stock_reservations ADD COLUMN unit_id BIGINT UNSIGNED NULL AFTER tenant_id;
ALTER TABLE stock_reservations ADD CONSTRAINT fk_stock_reservation_unit FOREIGN KEY (unit_id) REFERENCES operating_units(id) ON DELETE SET NULL;
ALTER TABLE stock_movements ADD COLUMN unit_id BIGINT UNSIGNED NULL AFTER tenant_id;
ALTER TABLE stock_movements ADD COLUMN reason VARCHAR(500) NULL;
ALTER TABLE stock_movements ADD COLUMN performed_by BIGINT UNSIGNED NULL;
ALTER TABLE stock_movements ADD CONSTRAINT fk_stock_movement_unit FOREIGN KEY (unit_id) REFERENCES operating_units(id) ON DELETE SET NULL;
ALTER TABLE stock_movements ADD CONSTRAINT fk_stock_movement_user FOREIGN KEY (performed_by) REFERENCES users(id) ON DELETE SET NULL;
CREATE INDEX idx_stock_reservation_unit ON stock_reservations(tenant_id,unit_id,product_id,status);
CREATE INDEX idx_stock_movement_unit ON stock_movements(tenant_id,unit_id,product_id,created_at);

UPDATE stock_reservations sr SET unit_id=COALESCE((SELECT o.unit_id FROM orders o WHERE o.id=sr.order_id),(SELECT MIN(ou.id) FROM operating_units ou WHERE ou.tenant_id=sr.tenant_id)) WHERE sr.unit_id IS NULL;
UPDATE stock_movements sm SET unit_id=COALESCE((SELECT o.unit_id FROM orders o WHERE o.id=sm.order_id),(SELECT MIN(ou.id) FROM operating_units ou WHERE ou.tenant_id=sm.tenant_id)) WHERE sm.unit_id IS NULL;

ALTER TABLE production_stations ADD COLUMN unit_id BIGINT UNSIGNED NULL AFTER tenant_id;
ALTER TABLE production_stations ADD CONSTRAINT fk_production_station_unit FOREIGN KEY (unit_id) REFERENCES operating_units(id) ON DELETE CASCADE;
ALTER TABLE production_prints ADD COLUMN unit_id BIGINT UNSIGNED NULL AFTER tenant_id;
ALTER TABLE production_prints ADD CONSTRAINT fk_production_print_unit FOREIGN KEY (unit_id) REFERENCES operating_units(id) ON DELETE SET NULL;
ALTER TABLE production_print_queue ADD COLUMN unit_id BIGINT UNSIGNED NULL AFTER tenant_id;
ALTER TABLE production_print_queue ADD CONSTRAINT fk_production_queue_unit FOREIGN KEY (unit_id) REFERENCES operating_units(id) ON DELETE CASCADE;
CREATE INDEX idx_production_station_unit ON production_stations(tenant_id,unit_id,active,sort_order,name);
CREATE INDEX idx_production_print_unit ON production_prints(tenant_id,unit_id,created_at);
CREATE INDEX idx_production_queue_unit ON production_print_queue(tenant_id,unit_id,status,created_at);

UPDATE production_stations ps SET unit_id=(SELECT MIN(ou.id) FROM operating_units ou WHERE ou.tenant_id=ps.tenant_id) WHERE ps.unit_id IS NULL;
UPDATE production_prints pp SET unit_id=COALESCE((SELECT o.unit_id FROM orders o WHERE o.id=pp.order_id),(SELECT ps.unit_id FROM production_stations ps WHERE ps.id=pp.station_id)) WHERE pp.unit_id IS NULL;
UPDATE production_print_queue pq SET unit_id=COALESCE((SELECT o.unit_id FROM orders o WHERE o.id=pq.order_id),(SELECT ps.unit_id FROM production_stations ps WHERE ps.id=pq.station_id)) WHERE pq.unit_id IS NULL;

CREATE TABLE product_unit_production_profiles (
  tenant_id BIGINT UNSIGNED NOT NULL,
  unit_id BIGINT UNSIGNED NOT NULL,
  product_id BIGINT UNSIGNED NOT NULL,
  station_id BIGINT UNSIGNED NULL,
  production_enabled TINYINT(1) NOT NULL DEFAULT 1,
  prep_minutes INT NULL,
  print_mode VARCHAR(20) NOT NULL DEFAULT 'inherit',
  production_notes VARCHAR(500) NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (tenant_id,unit_id,product_id),
  CONSTRAINT fk_pupp_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
  CONSTRAINT fk_pupp_unit FOREIGN KEY (unit_id) REFERENCES operating_units(id) ON DELETE CASCADE,
  CONSTRAINT fk_pupp_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
  CONSTRAINT fk_pupp_station FOREIGN KEY (station_id) REFERENCES production_stations(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE order_item_fulfillment_corrections (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id BIGINT UNSIGNED NOT NULL,
  order_id BIGINT UNSIGNED NOT NULL,
  order_item_id BIGINT UNSIGNED NOT NULL,
  fulfillment_id BIGINT UNSIGNED NOT NULL,
  quantity DECIMAL(14,3) NOT NULL,
  reason VARCHAR(500) NOT NULL,
  requested_by BIGINT UNSIGNED NULL,
  approved_by BIGINT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_ful_corr_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
  CONSTRAINT fk_ful_corr_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
  CONSTRAINT fk_ful_corr_item FOREIGN KEY (order_item_id) REFERENCES order_items(id) ON DELETE CASCADE,
  CONSTRAINT fk_ful_corr_fulfillment FOREIGN KEY (fulfillment_id) REFERENCES order_item_fulfillments(id) ON DELETE RESTRICT,
  CONSTRAINT fk_ful_corr_requested FOREIGN KEY (requested_by) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_ful_corr_approved FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE RESTRICT,
  INDEX idx_fulfillment_correction_item (tenant_id,order_item_id,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE suppliers (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id BIGINT UNSIGNED NOT NULL,
  name VARCHAR(180) NOT NULL,
  document VARCHAR(40) NULL,
  phone VARCHAR(40) NULL,
  email VARCHAR(180) NULL,
  notes VARCHAR(1000) NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_supplier_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
  INDEX idx_suppliers_tenant (tenant_id,active,name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE purchase_orders (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id BIGINT UNSIGNED NOT NULL,
  unit_id BIGINT UNSIGNED NOT NULL,
  supplier_id BIGINT UNSIGNED NULL,
  status VARCHAR(30) NOT NULL DEFAULT 'draft',
  total_cents BIGINT NOT NULL DEFAULT 0,
  notes VARCHAR(1000) NULL,
  created_by BIGINT UNSIGNED NULL,
  received_by BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  received_at DATETIME NULL,
  CONSTRAINT fk_purchase_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
  CONSTRAINT fk_purchase_unit FOREIGN KEY (unit_id) REFERENCES operating_units(id) ON DELETE RESTRICT,
  CONSTRAINT fk_purchase_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers(id) ON DELETE SET NULL,
  CONSTRAINT fk_purchase_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_purchase_receiver FOREIGN KEY (received_by) REFERENCES users(id) ON DELETE SET NULL,
  INDEX idx_purchase_orders_unit (tenant_id,unit_id,status,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE purchase_order_items (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  purchase_order_id BIGINT UNSIGNED NOT NULL,
  product_id BIGINT UNSIGNED NOT NULL,
  quantity DECIMAL(14,3) NOT NULL,
  unit_cost_cents BIGINT NOT NULL DEFAULT 0,
  total_cents BIGINT NOT NULL DEFAULT 0,
  CONSTRAINT fk_purchase_item_order FOREIGN KEY (purchase_order_id) REFERENCES purchase_orders(id) ON DELETE CASCADE,
  CONSTRAINT fk_purchase_item_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE inventory_counts (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id BIGINT UNSIGNED NOT NULL,
  unit_id BIGINT UNSIGNED NOT NULL,
  status VARCHAR(30) NOT NULL DEFAULT 'open',
  notes VARCHAR(1000) NULL,
  created_by BIGINT UNSIGNED NULL,
  approved_by BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  approved_at DATETIME NULL,
  CONSTRAINT fk_inventory_count_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
  CONSTRAINT fk_inventory_count_unit FOREIGN KEY (unit_id) REFERENCES operating_units(id) ON DELETE RESTRICT,
  CONSTRAINT fk_inventory_count_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_inventory_count_approver FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE SET NULL,
  INDEX idx_inventory_counts_unit (tenant_id,unit_id,status,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE inventory_count_items (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  inventory_count_id BIGINT UNSIGNED NOT NULL,
  product_id BIGINT UNSIGNED NOT NULL,
  expected_qty DECIMAL(14,3) NOT NULL,
  counted_qty DECIMAL(14,3) NULL,
  difference_qty DECIMAL(14,3) NULL,
  CONSTRAINT fk_inventory_count_item_count FOREIGN KEY (inventory_count_id) REFERENCES inventory_counts(id) ON DELETE CASCADE,
  CONSTRAINT fk_inventory_count_item_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE RESTRICT,
  UNIQUE KEY uq_inventory_count_item (inventory_count_id,product_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE inventory_waste (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id BIGINT UNSIGNED NOT NULL,
  unit_id BIGINT UNSIGNED NOT NULL,
  product_id BIGINT UNSIGNED NOT NULL,
  quantity DECIMAL(14,3) NOT NULL,
  reason_type VARCHAR(40) NOT NULL,
  reason VARCHAR(500) NULL,
  cost_cents BIGINT NOT NULL DEFAULT 0,
  user_id BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_inventory_waste_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
  CONSTRAINT fk_inventory_waste_unit FOREIGN KEY (unit_id) REFERENCES operating_units(id) ON DELETE RESTRICT,
  CONSTRAINT fk_inventory_waste_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE RESTRICT,
  CONSTRAINT fk_inventory_waste_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
  INDEX idx_inventory_waste_unit (tenant_id,unit_id,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
