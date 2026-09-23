CREATE TABLE IF NOT EXISTS marketplace_delivery_coupons (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  code TEXT NOT NULL UNIQUE,
  name TEXT NOT NULL,
  type TEXT NOT NULL,
  value INTEGER NOT NULL,
  min_order_cents INTEGER NOT NULL DEFAULT 0,
  max_uses_per_tenant INTEGER NULL,
  scope_type TEXT NOT NULL DEFAULT 'default',
  tenant_id INTEGER NULL,
  city TEXT NULL,
  state TEXT NULL,
  plan_code TEXT NULL,
  starts_at TEXT NULL,
  ends_at TEXT NULL,
  active INTEGER NOT NULL DEFAULT 1,
  created_by INTEGER NULL,
  created_at TEXT DEFAULT CURRENT_TIMESTAMP,
  updated_at TEXT DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE SET NULL,
  FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS marketplace_delivery_coupon_targets (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  marketplace_coupon_id INTEGER NOT NULL,
  tenant_id INTEGER NOT NULL,
  coupon_id INTEGER NOT NULL,
  created_at TEXT DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (marketplace_coupon_id) REFERENCES marketplace_delivery_coupons(id) ON DELETE CASCADE,
  FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
  FOREIGN KEY (coupon_id) REFERENCES coupons(id) ON DELETE CASCADE,
  UNIQUE (marketplace_coupon_id, tenant_id),
  UNIQUE (coupon_id)
);

CREATE INDEX IF NOT EXISTS idx_marketplace_delivery_coupon_scope ON marketplace_delivery_coupons(active, scope_type, tenant_id, city, state, plan_code);
CREATE INDEX IF NOT EXISTS idx_marketplace_delivery_coupon_tenant ON marketplace_delivery_coupon_targets(tenant_id);
