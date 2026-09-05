PRAGMA foreign_keys = ON;

CREATE TABLE IF NOT EXISTS tenants (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  name TEXT NOT NULL,
  slug TEXT NOT NULL UNIQUE,
  plan TEXT NOT NULL DEFAULT 'premium',
  status TEXT NOT NULL DEFAULT 'active',
  settings TEXT NULL,
  created_at TEXT DEFAULT CURRENT_TIMESTAMP,
  updated_at TEXT DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS users (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  tenant_id INTEGER NULL,
  name TEXT NOT NULL,
  email TEXT NOT NULL UNIQUE,
  phone TEXT NULL,
  password_hash TEXT NOT NULL,
  role TEXT NOT NULL DEFAULT 'admin',
  status TEXT NOT NULL DEFAULT 'active',
  last_login_at TEXT NULL,
  created_at TEXT DEFAULT CURRENT_TIMESTAMP,
  updated_at TEXT DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS categories (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  tenant_id INTEGER NOT NULL,
  name TEXT NOT NULL,
  sort_order INTEGER NOT NULL DEFAULT 0,
  active INTEGER NOT NULL DEFAULT 1,
  created_at TEXT DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS products (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  tenant_id INTEGER NOT NULL,
  category_id INTEGER NULL,
  name TEXT NOT NULL,
  description TEXT NULL,
  sku TEXT NULL,
  price_cents INTEGER NOT NULL,
  stock_qty REAL NULL,
  track_stock INTEGER NOT NULL DEFAULT 0,
  image_url TEXT NULL,
  active INTEGER NOT NULL DEFAULT 1,
  created_at TEXT DEFAULT CURRENT_TIMESTAMP,
  updated_at TEXT DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
  FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE SET NULL,
  UNIQUE (tenant_id, sku)
);

CREATE TABLE IF NOT EXISTS customers (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  tenant_id INTEGER NOT NULL,
  name TEXT NOT NULL,
  phone TEXT NULL,
  email TEXT NULL,
  document TEXT NULL,
  points INTEGER NOT NULL DEFAULT 0,
  created_at TEXT DEFAULT CURRENT_TIMESTAMP,
  updated_at TEXT DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS restaurant_tables (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  tenant_id INTEGER NOT NULL,
  name TEXT NOT NULL,
  seats INTEGER NOT NULL DEFAULT 4,
  status TEXT NOT NULL DEFAULT 'available',
  qr_token TEXT NOT NULL,
  created_at TEXT DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
  UNIQUE (tenant_id, name),
  UNIQUE (qr_token)
);

CREATE TABLE IF NOT EXISTS tabs (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  tenant_id INTEGER NOT NULL,
  table_id INTEGER NULL,
  customer_id INTEGER NULL,
  opened_by INTEGER NULL,
  closed_by INTEGER NULL,
  label TEXT NULL,
  status TEXT NOT NULL DEFAULT 'open',
  opened_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  closed_at TEXT NULL,
  FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
  FOREIGN KEY (table_id) REFERENCES restaurant_tables(id) ON DELETE SET NULL,
  FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE SET NULL,
  FOREIGN KEY (opened_by) REFERENCES users(id) ON DELETE SET NULL,
  FOREIGN KEY (closed_by) REFERENCES users(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS coupons (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  tenant_id INTEGER NOT NULL,
  code TEXT NOT NULL,
  type TEXT NOT NULL,
  value INTEGER NOT NULL,
  min_order_cents INTEGER NOT NULL DEFAULT 0,
  max_uses INTEGER NULL,
  uses_count INTEGER NOT NULL DEFAULT 0,
  reserved_count INTEGER NOT NULL DEFAULT 0,
  starts_at TEXT NULL,
  ends_at TEXT NULL,
  active INTEGER NOT NULL DEFAULT 1,
  created_at TEXT DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
  UNIQUE (tenant_id, code)
);

CREATE TABLE IF NOT EXISTS promoters (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  tenant_id INTEGER NOT NULL,
  user_id INTEGER NULL,
  name TEXT NOT NULL,
  code TEXT NOT NULL,
  commission_percent REAL NOT NULL DEFAULT 0,
  active INTEGER NOT NULL DEFAULT 1,
  created_at TEXT DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
  UNIQUE (tenant_id, code)
);

CREATE TABLE IF NOT EXISTS orders (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  public_token TEXT NULL UNIQUE,
  tenant_id INTEGER NOT NULL,
  customer_id INTEGER NULL,
  assigned_delivery_user_id INTEGER NULL,
  table_id INTEGER NULL,
  tab_id INTEGER NULL,
  coupon_id INTEGER NULL,
  promoter_id INTEGER NULL,
  channel TEXT NOT NULL DEFAULT 'counter',
  status TEXT NOT NULL DEFAULT 'pending',
  payment_status TEXT NOT NULL DEFAULT 'unpaid',
  subtotal_cents INTEGER NOT NULL DEFAULT 0,
  discount_cents INTEGER NOT NULL DEFAULT 0,
  delivery_fee_cents INTEGER NOT NULL DEFAULT 0,
  total_cents INTEGER NOT NULL DEFAULT 0,
  notes TEXT NULL,
  delivery_address TEXT NULL,
  created_by INTEGER NULL,
  created_at TEXT DEFAULT CURRENT_TIMESTAMP,
  updated_at TEXT DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
  FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE SET NULL,
  FOREIGN KEY (assigned_delivery_user_id) REFERENCES users(id) ON DELETE SET NULL,
  FOREIGN KEY (table_id) REFERENCES restaurant_tables(id) ON DELETE SET NULL,
  FOREIGN KEY (tab_id) REFERENCES tabs(id) ON DELETE SET NULL,
  FOREIGN KEY (coupon_id) REFERENCES coupons(id) ON DELETE SET NULL,
  FOREIGN KEY (promoter_id) REFERENCES promoters(id) ON DELETE SET NULL,
  FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS order_items (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  order_id INTEGER NOT NULL,
  product_id INTEGER NULL,
  name_snapshot TEXT NOT NULL,
  unit_price_cents INTEGER NOT NULL,
  quantity REAL NOT NULL DEFAULT 1,
  total_cents INTEGER NOT NULL,
  notes TEXT NULL,
  FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
  FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS events (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  tenant_id INTEGER NOT NULL,
  name TEXT NOT NULL,
  slug TEXT NOT NULL,
  description TEXT NULL,
  venue TEXT NULL,
  address TEXT NULL,
  starts_at TEXT NOT NULL,
  ends_at TEXT NULL,
  status TEXT NOT NULL DEFAULT 'draft',
  banner_url TEXT NULL,
  created_at TEXT DEFAULT CURRENT_TIMESTAMP,
  updated_at TEXT DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
  UNIQUE (tenant_id, slug)
);

CREATE TABLE IF NOT EXISTS ticket_batches (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  event_id INTEGER NOT NULL,
  name TEXT NOT NULL,
  price_cents INTEGER NOT NULL,
  quantity_total INTEGER NOT NULL,
  quantity_sold INTEGER NOT NULL DEFAULT 0,
  quantity_reserved INTEGER NOT NULL DEFAULT 0,
  sales_start TEXT NULL,
  sales_end TEXT NULL,
  active INTEGER NOT NULL DEFAULT 1,
  FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS tickets (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  tenant_id INTEGER NOT NULL,
  event_id INTEGER NOT NULL,
  batch_id INTEGER NOT NULL,
  order_id INTEGER NULL,
  customer_id INTEGER NULL,
  code TEXT NOT NULL UNIQUE,
  qr_token TEXT NULL UNIQUE,
  status TEXT NOT NULL DEFAULT 'reserved',
  reserved_until TEXT NULL,
  checked_in_at TEXT NULL,
  checked_in_by INTEGER NULL,
  created_at TEXT DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
  FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
  FOREIGN KEY (batch_id) REFERENCES ticket_batches(id) ON DELETE CASCADE,
  FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE SET NULL,
  FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE SET NULL,
  FOREIGN KEY (checked_in_by) REFERENCES users(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS payment_gateways (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  tenant_id INTEGER NOT NULL,
  provider TEXT NOT NULL,
  account_reference TEXT NULL,
  config_encrypted TEXT NULL,
  webhook_secret_encrypted TEXT NULL,
  active INTEGER NOT NULL DEFAULT 0,
  created_at TEXT DEFAULT CURRENT_TIMESTAMP,
  updated_at TEXT DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
  UNIQUE (tenant_id, provider)
);

CREATE TABLE IF NOT EXISTS payments (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  tenant_id INTEGER NOT NULL,
  order_id INTEGER NOT NULL,
  provider TEXT NOT NULL,
  provider_payment_id TEXT NULL,
  idempotency_key TEXT NOT NULL,
  amount_cents INTEGER NOT NULL,
  currency TEXT NOT NULL DEFAULT 'BRL',
  status TEXT NOT NULL DEFAULT 'created',
  verified_at TEXT NULL,
  raw_payload TEXT NULL,
  created_at TEXT DEFAULT CURRENT_TIMESTAMP,
  updated_at TEXT DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
  FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE RESTRICT,
  UNIQUE (tenant_id, idempotency_key),
  UNIQUE (provider, provider_payment_id)
);

CREATE TABLE IF NOT EXISTS webhook_events (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  tenant_id INTEGER NULL,
  provider TEXT NOT NULL,
  external_event_id TEXT NOT NULL,
  signature_valid INTEGER NOT NULL DEFAULT 0,
  payload_hash TEXT NOT NULL,
  processed_at TEXT NULL,
  status TEXT NOT NULL DEFAULT 'received',
  created_at TEXT DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
  UNIQUE (provider, external_event_id)
);

CREATE TABLE IF NOT EXISTS stock_movements (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  tenant_id INTEGER NOT NULL,
  product_id INTEGER NOT NULL,
  order_id INTEGER NULL,
  type TEXT NOT NULL,
  quantity REAL NOT NULL,
  idempotency_key TEXT NOT NULL,
  created_at TEXT DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
  FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
  FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE SET NULL,
  UNIQUE (tenant_id, idempotency_key)
);

CREATE TABLE IF NOT EXISTS audit_logs (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  tenant_id INTEGER NULL,
  user_id INTEGER NULL,
  action TEXT NOT NULL,
  entity_type TEXT NULL,
  entity_id TEXT NULL,
  ip_address TEXT NULL,
  user_agent TEXT NULL,
  metadata TEXT NULL,
  created_at TEXT DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS coupon_redemptions (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  tenant_id INTEGER NOT NULL,
  coupon_id INTEGER NOT NULL,
  order_id INTEGER NOT NULL,
  customer_id INTEGER NULL,
  discount_cents INTEGER NOT NULL,
  idempotency_key TEXT NOT NULL,
  created_at TEXT DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
  FOREIGN KEY (coupon_id) REFERENCES coupons(id) ON DELETE CASCADE,
  FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
  FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE SET NULL,
  UNIQUE (tenant_id, idempotency_key)
);

CREATE TABLE IF NOT EXISTS customer_points_movements (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  tenant_id INTEGER NOT NULL,
  customer_id INTEGER NOT NULL,
  order_id INTEGER NULL,
  points INTEGER NOT NULL,
  type TEXT NOT NULL,
  idempotency_key TEXT NOT NULL,
  created_at TEXT DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
  FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE,
  FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE SET NULL,
  UNIQUE (tenant_id, idempotency_key)
);

CREATE TABLE IF NOT EXISTS promoter_commissions (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  tenant_id INTEGER NOT NULL,
  promoter_id INTEGER NOT NULL,
  order_id INTEGER NOT NULL,
  amount_cents INTEGER NOT NULL,
  status TEXT NOT NULL DEFAULT 'pending',
  idempotency_key TEXT NOT NULL,
  created_at TEXT DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
  FOREIGN KEY (promoter_id) REFERENCES promoters(id) ON DELETE CASCADE,
  FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
  UNIQUE (tenant_id, idempotency_key)
);

CREATE TABLE IF NOT EXISTS event_guests (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  tenant_id INTEGER NOT NULL,
  event_id INTEGER NOT NULL,
  promoter_id INTEGER NULL,
  name TEXT NOT NULL,
  document TEXT NULL,
  phone TEXT NULL,
  plus_ones INTEGER NOT NULL DEFAULT 0,
  status TEXT NOT NULL DEFAULT 'invited',
  checkin_code TEXT NOT NULL UNIQUE,
  checked_in_at TEXT NULL,
  checked_in_by INTEGER NULL,
  created_at TEXT DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
  FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
  FOREIGN KEY (promoter_id) REFERENCES promoters(id) ON DELETE SET NULL,
  FOREIGN KEY (checked_in_by) REFERENCES users(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS ticket_checkin_logs (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  tenant_id INTEGER NOT NULL,
  ticket_id INTEGER NOT NULL,
  user_id INTEGER NULL,
  result TEXT NOT NULL,
  metadata TEXT NULL,
  created_at TEXT DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
  FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS nfc_devices (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  tenant_id INTEGER NOT NULL,
  user_id INTEGER NULL,
  provider TEXT NOT NULL DEFAULT 'pagbank',
  device_identifier_hash TEXT NOT NULL,
  name TEXT NULL,
  status TEXT NOT NULL DEFAULT 'pending',
  pairing_attempts INTEGER NOT NULL DEFAULT 0,
  paired_at TEXT NULL,
  revoked_at TEXT NULL,
  created_at TEXT DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
  UNIQUE (tenant_id, device_identifier_hash)
);

CREATE TABLE IF NOT EXISTS coupon_reservations (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  tenant_id INTEGER NOT NULL,
  coupon_id INTEGER NOT NULL,
  order_id INTEGER NOT NULL UNIQUE,
  discount_cents INTEGER NOT NULL,
  status TEXT NOT NULL DEFAULT 'reserved',
  expires_at TEXT NOT NULL,
  created_at TEXT DEFAULT CURRENT_TIMESTAMP,
  updated_at TEXT DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
  FOREIGN KEY (coupon_id) REFERENCES coupons(id) ON DELETE CASCADE,
  FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_users_tenant_role ON users(tenant_id, role);
CREATE INDEX IF NOT EXISTS idx_categories_tenant ON categories(tenant_id, active, sort_order);
CREATE INDEX IF NOT EXISTS idx_products_catalog ON products(tenant_id, active, category_id);
CREATE INDEX IF NOT EXISTS idx_customers_lookup ON customers(tenant_id, phone, email);
CREATE INDEX IF NOT EXISTS idx_orders_dashboard ON orders(tenant_id, status, created_at);
CREATE INDEX IF NOT EXISTS idx_orders_delivery ON orders(tenant_id, assigned_delivery_user_id, status);
CREATE INDEX IF NOT EXISTS idx_order_items_order ON order_items(order_id);
CREATE INDEX IF NOT EXISTS idx_events_public ON events(tenant_id, status, starts_at);
CREATE INDEX IF NOT EXISTS idx_batches_event ON ticket_batches(event_id, active);
CREATE INDEX IF NOT EXISTS idx_tickets_event_status ON tickets(event_id, status);
CREATE INDEX IF NOT EXISTS idx_payments_order ON payments(tenant_id, order_id, status);
CREATE INDEX IF NOT EXISTS idx_webhook_tenant_created ON webhook_events(tenant_id, created_at);
CREATE INDEX IF NOT EXISTS idx_audit_tenant ON audit_logs(tenant_id, created_at);
CREATE INDEX IF NOT EXISTS idx_tabs_open ON tabs(tenant_id, status, table_id);
CREATE INDEX IF NOT EXISTS idx_guests_event ON event_guests(event_id, status, name);
CREATE INDEX IF NOT EXISTS idx_ticket_checkin ON ticket_checkin_logs(ticket_id, created_at);
CREATE INDEX IF NOT EXISTS idx_coupon_res_expiry ON coupon_reservations(tenant_id, status, expires_at);
