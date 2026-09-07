PRAGMA foreign_keys = ON;

ALTER TABLE orders ADD COLUMN coupon_discount_cents INTEGER NOT NULL DEFAULT 0;
ALTER TABLE orders ADD COLUMN manual_discount_cents INTEGER NOT NULL DEFAULT 0;
ALTER TABLE orders ADD COLUMN surcharge_cents INTEGER NOT NULL DEFAULT 0;
UPDATE orders SET coupon_discount_cents=discount_cents WHERE coupon_id IS NOT NULL AND discount_cents>0;
UPDATE orders SET manual_discount_cents=discount_cents WHERE coupon_id IS NULL AND discount_cents>0;

ALTER TABLE order_discount_requests ADD COLUMN discount_type TEXT NOT NULL DEFAULT 'fixed';
ALTER TABLE order_discount_requests ADD COLUMN requested_bps INTEGER NOT NULL DEFAULT 0;
ALTER TABLE order_discount_requests ADD COLUMN applied_cents INTEGER NOT NULL DEFAULT 0;
ALTER TABLE order_discount_requests ADD COLUMN auto_approved INTEGER NOT NULL DEFAULT 0;
ALTER TABLE order_discount_requests ADD COLUMN unit_id INTEGER NULL REFERENCES operating_units(id) ON DELETE SET NULL;
CREATE INDEX idx_discount_requests_unit ON order_discount_requests(tenant_id,unit_id,status,created_at);

CREATE TABLE discount_policies (
  tenant_id INTEGER PRIMARY KEY REFERENCES tenants(id) ON DELETE CASCADE,
  cashier_auto_bps INTEGER NOT NULL DEFAULT 500,
  manager_auto_bps INTEGER NOT NULL DEFAULT 2000,
  admin_auto_bps INTEGER NOT NULL DEFAULT 10000,
  require_reason INTEGER NOT NULL DEFAULT 1,
  allow_percentage INTEGER NOT NULL DEFAULT 1,
  updated_by INTEGER NULL REFERENCES users(id) ON DELETE SET NULL,
  updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE order_price_adjustments (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  tenant_id INTEGER NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
  order_id INTEGER NOT NULL REFERENCES orders(id) ON DELETE CASCADE,
  order_item_id INTEGER NULL REFERENCES order_items(id) ON DELETE CASCADE,
  direction TEXT NOT NULL,
  adjustment_type TEXT NOT NULL,
  label TEXT NOT NULL,
  amount_cents INTEGER NOT NULL,
  percent_bps INTEGER NOT NULL DEFAULT 0,
  request_id INTEGER NULL REFERENCES order_discount_requests(id) ON DELETE SET NULL,
  authorized_by INTEGER NULL REFERENCES users(id) ON DELETE SET NULL,
  active INTEGER NOT NULL DEFAULT 1,
  idempotency_key TEXT NOT NULL,
  metadata TEXT NULL,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE (tenant_id,idempotency_key)
);
CREATE INDEX idx_price_adjustment_order ON order_price_adjustments(tenant_id,order_id,active);

CREATE TABLE financial_accounts (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  tenant_id INTEGER NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
  unit_id INTEGER NULL REFERENCES operating_units(id) ON DELETE SET NULL,
  name TEXT NOT NULL,
  account_type TEXT NOT NULL,
  provider TEXT NULL,
  opening_balance_cents INTEGER NOT NULL DEFAULT 0,
  active INTEGER NOT NULL DEFAULT 1,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX idx_fin_account_tenant ON financial_accounts(tenant_id,active,account_type);

CREATE TABLE financial_categories (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  tenant_id INTEGER NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
  name TEXT NOT NULL,
  direction TEXT NOT NULL,
  dre_group TEXT NOT NULL,
  active INTEGER NOT NULL DEFAULT 1,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE (tenant_id,name,direction)
);

CREATE TABLE financial_entries (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  tenant_id INTEGER NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
  unit_id INTEGER NULL REFERENCES operating_units(id) ON DELETE SET NULL,
  event_id INTEGER NULL REFERENCES events(id) ON DELETE SET NULL,
  order_id INTEGER NULL REFERENCES orders(id) ON DELETE SET NULL,
  payment_id INTEGER NULL REFERENCES payments(id) ON DELETE SET NULL,
  purchase_order_id INTEGER NULL REFERENCES purchase_orders(id) ON DELETE SET NULL,
  supplier_id INTEGER NULL REFERENCES suppliers(id) ON DELETE SET NULL,
  account_id INTEGER NULL REFERENCES financial_accounts(id) ON DELETE SET NULL,
  category_id INTEGER NULL REFERENCES financial_categories(id) ON DELETE SET NULL,
  direction TEXT NOT NULL,
  entry_type TEXT NOT NULL,
  description TEXT NOT NULL,
  gross_cents INTEGER NOT NULL,
  fee_cents INTEGER NOT NULL DEFAULT 0,
  net_cents INTEGER NOT NULL,
  affects_result INTEGER NOT NULL DEFAULT 1,
  affects_cash INTEGER NOT NULL DEFAULT 0,
  status TEXT NOT NULL DEFAULT 'open',
  competence_date TEXT NOT NULL,
  due_date TEXT NULL,
  settled_at TEXT NULL,
  external_reference TEXT NULL,
  idempotency_key TEXT NOT NULL,
  metadata TEXT NULL,
  created_by INTEGER NULL REFERENCES users(id) ON DELETE SET NULL,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE (tenant_id,idempotency_key)
);
CREATE INDEX idx_fin_entry_scope ON financial_entries(tenant_id,unit_id,competence_date,direction,status);
CREATE INDEX idx_fin_entry_due ON financial_entries(tenant_id,status,due_date);
CREATE INDEX idx_fin_entry_event ON financial_entries(tenant_id,event_id,competence_date);
CREATE INDEX idx_fin_entry_result ON financial_entries(tenant_id,affects_result,competence_date);
CREATE INDEX idx_fin_entry_cash ON financial_entries(tenant_id,affects_cash,status,due_date);

CREATE TABLE payment_fee_rules (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  tenant_id INTEGER NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
  provider TEXT NOT NULL,
  payment_method TEXT NOT NULL DEFAULT '*',
  percent_bps INTEGER NOT NULL DEFAULT 0,
  fixed_cents INTEGER NOT NULL DEFAULT 0,
  settlement_days INTEGER NOT NULL DEFAULT 0,
  active INTEGER NOT NULL DEFAULT 1,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE (tenant_id,provider,payment_method)
);
