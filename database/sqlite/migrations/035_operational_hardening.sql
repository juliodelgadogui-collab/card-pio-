PRAGMA foreign_keys = ON;

-- Toda empresa passa a ter pelo menos uma unidade operacional.
INSERT INTO operating_units (tenant_id,code,name,address,active)
SELECT t.id,'principal','Principal',NULL,1
FROM tenants t
WHERE NOT EXISTS (SELECT 1 FROM operating_units ou WHERE ou.tenant_id=t.id);

-- Dados legados passam para a primeira unidade da empresa.
UPDATE orders SET unit_id=(SELECT MIN(ou.id) FROM operating_units ou WHERE ou.tenant_id=orders.tenant_id) WHERE unit_id IS NULL;
UPDATE cash_sessions SET unit_id=(SELECT MIN(ou.id) FROM operating_units ou WHERE ou.tenant_id=cash_sessions.tenant_id) WHERE unit_id IS NULL;
UPDATE restaurant_tables SET unit_id=(SELECT MIN(ou.id) FROM operating_units ou WHERE ou.tenant_id=restaurant_tables.tenant_id) WHERE unit_id IS NULL;
UPDATE work_shifts SET unit_id=(SELECT MIN(ou.id) FROM operating_units ou WHERE ou.tenant_id=work_shifts.tenant_id) WHERE unit_id IS NULL;

ALTER TABLE products ADD COLUMN stock_unit TEXT NOT NULL DEFAULT 'un';
ALTER TABLE products ADD COLUMN purchase_unit TEXT NOT NULL DEFAULT 'un';
ALTER TABLE products ADD COLUMN purchase_factor REAL NOT NULL DEFAULT 1;
ALTER TABLE products ADD COLUMN min_stock_qty REAL NOT NULL DEFAULT 0;

CREATE TABLE unit_inventory (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  tenant_id INTEGER NOT NULL,
  unit_id INTEGER NOT NULL,
  product_id INTEGER NOT NULL,
  stock_qty REAL NOT NULL DEFAULT 0,
  average_cost_cents INTEGER NOT NULL DEFAULT 0,
  min_stock_qty REAL NOT NULL DEFAULT 0,
  updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
  FOREIGN KEY (unit_id) REFERENCES operating_units(id) ON DELETE CASCADE,
  FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
  UNIQUE (tenant_id,unit_id,product_id)
);
CREATE INDEX idx_unit_inventory_lookup ON unit_inventory(tenant_id,unit_id,product_id);
CREATE INDEX idx_unit_inventory_low ON unit_inventory(tenant_id,unit_id,stock_qty,min_stock_qty);

INSERT INTO unit_inventory (tenant_id,unit_id,product_id,stock_qty,average_cost_cents,min_stock_qty)
SELECT p.tenant_id,
       (SELECT MIN(ou.id) FROM operating_units ou WHERE ou.tenant_id=p.tenant_id),
       p.id,p.stock_qty,p.average_cost_cents,p.min_stock_qty
FROM products p
WHERE (SELECT MIN(ou.id) FROM operating_units ou WHERE ou.tenant_id=p.tenant_id) IS NOT NULL;

ALTER TABLE stock_reservations ADD COLUMN unit_id INTEGER NULL REFERENCES operating_units(id) ON DELETE SET NULL;
ALTER TABLE stock_movements ADD COLUMN unit_id INTEGER NULL REFERENCES operating_units(id) ON DELETE SET NULL;
ALTER TABLE stock_movements ADD COLUMN reason TEXT NULL;
ALTER TABLE stock_movements ADD COLUMN performed_by INTEGER NULL REFERENCES users(id) ON DELETE SET NULL;
CREATE INDEX idx_stock_reservation_unit ON stock_reservations(tenant_id,unit_id,product_id,status);
CREATE INDEX idx_stock_movement_unit ON stock_movements(tenant_id,unit_id,product_id,created_at);

UPDATE stock_reservations
SET unit_id=COALESCE((SELECT o.unit_id FROM orders o WHERE o.id=stock_reservations.order_id),(SELECT MIN(ou.id) FROM operating_units ou WHERE ou.tenant_id=stock_reservations.tenant_id))
WHERE unit_id IS NULL;
UPDATE stock_movements
SET unit_id=COALESCE((SELECT o.unit_id FROM orders o WHERE o.id=stock_movements.order_id),(SELECT MIN(ou.id) FROM operating_units ou WHERE ou.tenant_id=stock_movements.tenant_id))
WHERE unit_id IS NULL;

ALTER TABLE production_stations ADD COLUMN unit_id INTEGER NULL REFERENCES operating_units(id) ON DELETE CASCADE;
ALTER TABLE production_prints ADD COLUMN unit_id INTEGER NULL REFERENCES operating_units(id) ON DELETE SET NULL;
ALTER TABLE production_print_queue ADD COLUMN unit_id INTEGER NULL REFERENCES operating_units(id) ON DELETE CASCADE;
CREATE INDEX idx_production_station_unit ON production_stations(tenant_id,unit_id,active,sort_order,name);
CREATE INDEX idx_production_print_unit ON production_prints(tenant_id,unit_id,created_at);
CREATE INDEX idx_production_queue_unit ON production_print_queue(tenant_id,unit_id,status,created_at);

UPDATE production_stations
SET unit_id=(SELECT MIN(ou.id) FROM operating_units ou WHERE ou.tenant_id=production_stations.tenant_id)
WHERE unit_id IS NULL;
UPDATE production_prints
SET unit_id=COALESCE((SELECT o.unit_id FROM orders o WHERE o.id=production_prints.order_id),(SELECT ps.unit_id FROM production_stations ps WHERE ps.id=production_prints.station_id))
WHERE unit_id IS NULL;
UPDATE production_print_queue
SET unit_id=COALESCE((SELECT o.unit_id FROM orders o WHERE o.id=production_print_queue.order_id),(SELECT ps.unit_id FROM production_stations ps WHERE ps.id=production_print_queue.station_id))
WHERE unit_id IS NULL;

CREATE TABLE product_unit_production_profiles (
  tenant_id INTEGER NOT NULL,
  unit_id INTEGER NOT NULL,
  product_id INTEGER NOT NULL,
  station_id INTEGER NULL,
  production_enabled INTEGER NOT NULL DEFAULT 1,
  prep_minutes INTEGER NULL,
  print_mode TEXT NOT NULL DEFAULT 'inherit',
  production_notes TEXT NULL,
  updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (tenant_id,unit_id,product_id),
  FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
  FOREIGN KEY (unit_id) REFERENCES operating_units(id) ON DELETE CASCADE,
  FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
  FOREIGN KEY (station_id) REFERENCES production_stations(id) ON DELETE SET NULL
);

-- Fulfillment permanece imutável. Erros são corrigidos por lançamento compensatório auditado.
CREATE TABLE order_item_fulfillment_corrections (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  tenant_id INTEGER NOT NULL,
  order_id INTEGER NOT NULL,
  order_item_id INTEGER NOT NULL,
  fulfillment_id INTEGER NOT NULL,
  quantity REAL NOT NULL,
  reason TEXT NOT NULL,
  requested_by INTEGER NULL,
  approved_by INTEGER NOT NULL,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
  FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
  FOREIGN KEY (order_item_id) REFERENCES order_items(id) ON DELETE CASCADE,
  FOREIGN KEY (fulfillment_id) REFERENCES order_item_fulfillments(id) ON DELETE RESTRICT,
  FOREIGN KEY (requested_by) REFERENCES users(id) ON DELETE SET NULL,
  FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE RESTRICT
);
CREATE INDEX idx_fulfillment_correction_item ON order_item_fulfillment_corrections(tenant_id,order_item_id,created_at);

CREATE TABLE suppliers (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  tenant_id INTEGER NOT NULL,
  name TEXT NOT NULL,
  document TEXT NULL,
  phone TEXT NULL,
  email TEXT NULL,
  notes TEXT NULL,
  active INTEGER NOT NULL DEFAULT 1,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE
);
CREATE INDEX idx_suppliers_tenant ON suppliers(tenant_id,active,name);

CREATE TABLE purchase_orders (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  tenant_id INTEGER NOT NULL,
  unit_id INTEGER NOT NULL,
  supplier_id INTEGER NULL,
  status TEXT NOT NULL DEFAULT 'draft',
  total_cents INTEGER NOT NULL DEFAULT 0,
  notes TEXT NULL,
  created_by INTEGER NULL,
  received_by INTEGER NULL,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  received_at TEXT NULL,
  FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
  FOREIGN KEY (unit_id) REFERENCES operating_units(id) ON DELETE RESTRICT,
  FOREIGN KEY (supplier_id) REFERENCES suppliers(id) ON DELETE SET NULL,
  FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
  FOREIGN KEY (received_by) REFERENCES users(id) ON DELETE SET NULL
);
CREATE INDEX idx_purchase_orders_unit ON purchase_orders(tenant_id,unit_id,status,created_at);

CREATE TABLE purchase_order_items (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  purchase_order_id INTEGER NOT NULL,
  product_id INTEGER NOT NULL,
  quantity REAL NOT NULL,
  unit_cost_cents INTEGER NOT NULL DEFAULT 0,
  total_cents INTEGER NOT NULL DEFAULT 0,
  FOREIGN KEY (purchase_order_id) REFERENCES purchase_orders(id) ON DELETE CASCADE,
  FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE RESTRICT
);

CREATE TABLE inventory_counts (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  tenant_id INTEGER NOT NULL,
  unit_id INTEGER NOT NULL,
  status TEXT NOT NULL DEFAULT 'open',
  notes TEXT NULL,
  created_by INTEGER NULL,
  approved_by INTEGER NULL,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  approved_at TEXT NULL,
  FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
  FOREIGN KEY (unit_id) REFERENCES operating_units(id) ON DELETE RESTRICT,
  FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
  FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE SET NULL
);
CREATE INDEX idx_inventory_counts_unit ON inventory_counts(tenant_id,unit_id,status,created_at);

CREATE TABLE inventory_count_items (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  inventory_count_id INTEGER NOT NULL,
  product_id INTEGER NOT NULL,
  expected_qty REAL NOT NULL,
  counted_qty REAL NULL,
  difference_qty REAL NULL,
  FOREIGN KEY (inventory_count_id) REFERENCES inventory_counts(id) ON DELETE CASCADE,
  FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE RESTRICT,
  UNIQUE (inventory_count_id,product_id)
);

CREATE TABLE inventory_waste (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  tenant_id INTEGER NOT NULL,
  unit_id INTEGER NOT NULL,
  product_id INTEGER NOT NULL,
  quantity REAL NOT NULL,
  reason_type TEXT NOT NULL,
  reason TEXT NULL,
  cost_cents INTEGER NOT NULL DEFAULT 0,
  user_id INTEGER NULL,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
  FOREIGN KEY (unit_id) REFERENCES operating_units(id) ON DELETE RESTRICT,
  FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE RESTRICT,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
);
CREATE INDEX idx_inventory_waste_unit ON inventory_waste(tenant_id,unit_id,created_at);
