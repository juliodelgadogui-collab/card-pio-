CREATE TABLE payment_groups (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  tenant_id INTEGER NOT NULL,
  tab_id INTEGER NULL,
  user_id INTEGER NULL,
  provider TEXT NOT NULL,
  method TEXT NOT NULL,
  split_type TEXT NOT NULL,
  idempotency_key TEXT NOT NULL,
  amount_cents INTEGER NOT NULL,
  currency TEXT NOT NULL DEFAULT 'BRL',
  status TEXT NOT NULL DEFAULT 'created',
  provider_payment_id TEXT NULL,
  metadata TEXT NULL,
  raw_payload TEXT NULL,
  verified_at TEXT NULL,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
  FOREIGN KEY (tab_id) REFERENCES tabs(id) ON DELETE RESTRICT,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
  UNIQUE (tenant_id,idempotency_key),
  UNIQUE (tenant_id,provider,provider_payment_id)
);
CREATE INDEX idx_payment_group_tab ON payment_groups(tenant_id,tab_id,status,created_at);

ALTER TABLE payments ADD COLUMN payment_group_id INTEGER NULL REFERENCES payment_groups(id) ON DELETE SET NULL;
CREATE INDEX idx_payments_group ON payments(tenant_id,payment_group_id,status);

CREATE TABLE payment_group_allocations (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  tenant_id INTEGER NOT NULL,
  payment_group_id INTEGER NOT NULL,
  order_id INTEGER NOT NULL,
  payment_id INTEGER NOT NULL,
  amount_cents INTEGER NOT NULL,
  metadata TEXT NULL,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
  FOREIGN KEY (payment_group_id) REFERENCES payment_groups(id) ON DELETE CASCADE,
  FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE RESTRICT,
  FOREIGN KEY (payment_id) REFERENCES payments(id) ON DELETE RESTRICT,
  UNIQUE (payment_group_id,order_id),
  UNIQUE (payment_id)
);
CREATE INDEX idx_payment_group_alloc_order ON payment_group_allocations(tenant_id,order_id,payment_group_id);

CREATE TABLE payment_group_items (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  tenant_id INTEGER NOT NULL,
  payment_group_id INTEGER NOT NULL,
  order_item_id INTEGER NOT NULL,
  amount_cents INTEGER NOT NULL,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
  FOREIGN KEY (payment_group_id) REFERENCES payment_groups(id) ON DELETE CASCADE,
  FOREIGN KEY (order_item_id) REFERENCES order_items(id) ON DELETE RESTRICT,
  UNIQUE (payment_group_id,order_item_id)
);
CREATE INDEX idx_payment_group_item_lookup ON payment_group_items(tenant_id,order_item_id,payment_group_id);
