ALTER TABLE orders ADD COLUMN order_source TEXT NOT NULL DEFAULT 'EVENTMENU_OWN';
ALTER TABLE orders ADD COLUMN marketplace_campaign_code TEXT NULL;
CREATE INDEX IF NOT EXISTS idx_orders_source_created ON orders (tenant_id,order_source,created_at);

CREATE TABLE marketplace_tenant_settings (
  tenant_id INTEGER PRIMARY KEY,
  participates INTEGER NOT NULL DEFAULT 0,
  status TEXT NOT NULL DEFAULT 'inactive',
  joined_at TEXT NULL,
  city TEXT NULL,
  state TEXT NULL,
  updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_marketplace_tenant_status ON marketplace_tenant_settings (participates,status,city,state);

CREATE TABLE marketplace_commission_rules (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  name TEXT NOT NULL,
  scope_type TEXT NOT NULL,
  tenant_id INTEGER NULL,
  city TEXT NULL,
  state TEXT NULL,
  plan_code TEXT NULL,
  campaign_code TEXT NULL,
  rate_bps INTEGER NOT NULL,
  priority INTEGER NOT NULL DEFAULT 0,
  starts_at TEXT NULL,
  ends_at TEXT NULL,
  active INTEGER NOT NULL DEFAULT 1,
  created_by INTEGER NULL,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
  FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
);
CREATE INDEX IF NOT EXISTS idx_marketplace_rule_match ON marketplace_commission_rules (active,scope_type,tenant_id,city,state,plan_code,campaign_code,starts_at,ends_at,priority);

INSERT INTO marketplace_commission_rules (name,scope_type,rate_bps,priority,active)
SELECT 'Taxa padrão EventMenu Delivery','default',400,0,1
WHERE NOT EXISTS (SELECT 1 FROM marketplace_commission_rules WHERE scope_type='default' AND active=1);

CREATE TABLE marketplace_order_commissions (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  tenant_id INTEGER NOT NULL,
  unit_id INTEGER NULL,
  order_id INTEGER NOT NULL UNIQUE,
  rule_id INTEGER NULL,
  order_source TEXT NOT NULL,
  products_gross_cents INTEGER NOT NULL DEFAULT 0,
  product_discount_cents INTEGER NOT NULL DEFAULT 0,
  excluded_adjustments_cents INTEGER NOT NULL DEFAULT 0,
  calculation_base_cents INTEGER NOT NULL DEFAULT 0,
  commission_bps INTEGER NOT NULL DEFAULT 0,
  commission_cents INTEGER NOT NULL DEFAULT 0,
  status TEXT NOT NULL DEFAULT 'provisioned',
  rule_snapshot TEXT NOT NULL,
  invoice_item_id INTEGER NULL,
  due_at TEXT NULL,
  invoiced_at TEXT NULL,
  paid_at TEXT NULL,
  reversed_at TEXT NULL,
  reversal_reason TEXT NULL,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
  FOREIGN KEY (unit_id) REFERENCES operating_units(id) ON DELETE SET NULL,
  FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE RESTRICT,
  FOREIGN KEY (rule_id) REFERENCES marketplace_commission_rules(id) ON DELETE SET NULL,
  FOREIGN KEY (invoice_item_id) REFERENCES platform_invoice_items(id) ON DELETE SET NULL
);
CREATE INDEX IF NOT EXISTS idx_marketplace_commission_status ON marketplace_order_commissions (tenant_id,status,created_at);

CREATE TABLE platform_invoices (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  tenant_id INTEGER NOT NULL,
  period_start TEXT NOT NULL,
  period_end TEXT NOT NULL,
  issued_at TEXT NULL,
  due_at TEXT NULL,
  status TEXT NOT NULL DEFAULT 'draft',
  subtotal_cents INTEGER NOT NULL DEFAULT 0,
  credits_cents INTEGER NOT NULL DEFAULT 0,
  total_cents INTEGER NOT NULL DEFAULT 0,
  paid_cents INTEGER NOT NULL DEFAULT 0,
  paid_at TEXT NULL,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
  UNIQUE (tenant_id,period_start,period_end)
);
CREATE INDEX IF NOT EXISTS idx_platform_invoice_status ON platform_invoices (status,due_at,tenant_id);

CREATE TABLE platform_invoice_items (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  invoice_id INTEGER NOT NULL,
  tenant_id INTEGER NOT NULL,
  item_type TEXT NOT NULL,
  order_id INTEGER NULL,
  marketplace_commission_id INTEGER NULL UNIQUE,
  description TEXT NOT NULL,
  quantity REAL NOT NULL DEFAULT 1,
  unit_amount_cents INTEGER NOT NULL,
  amount_cents INTEGER NOT NULL,
  snapshot_json TEXT NULL,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (invoice_id) REFERENCES platform_invoices(id) ON DELETE CASCADE,
  FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
  FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE SET NULL,
  FOREIGN KEY (marketplace_commission_id) REFERENCES marketplace_order_commissions(id) ON DELETE SET NULL
);
CREATE INDEX IF NOT EXISTS idx_platform_invoice_item_invoice ON platform_invoice_items (invoice_id,item_type);

CREATE TABLE marketplace_promotion_accounts (
  tenant_id INTEGER PRIMARY KEY,
  participates INTEGER NOT NULL DEFAULT 0,
  balance_cents INTEGER NOT NULL DEFAULT 0,
  updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE
);

CREATE TABLE marketplace_promotion_ledger (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  tenant_id INTEGER NOT NULL,
  order_id INTEGER NULL,
  entry_type TEXT NOT NULL,
  amount_cents INTEGER NOT NULL,
  description TEXT NOT NULL,
  reference_key TEXT NOT NULL,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
  FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE SET NULL,
  UNIQUE (tenant_id,reference_key)
);
CREATE INDEX IF NOT EXISTS idx_marketplace_promotion_created ON marketplace_promotion_ledger (tenant_id,entry_type,created_at);
