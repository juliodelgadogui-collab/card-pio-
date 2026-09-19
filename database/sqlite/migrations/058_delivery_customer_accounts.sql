CREATE TABLE IF NOT EXISTS delivery_customer_accounts (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  name TEXT NOT NULL,
  email TEXT NOT NULL UNIQUE,
  phone TEXT NULL,
  password_hash TEXT NOT NULL,
  email_verified_at TEXT NULL,
  status TEXT NOT NULL DEFAULT 'pending_verification',
  marketing_opt_in INTEGER NOT NULL DEFAULT 0,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_delivery_customer_status ON delivery_customer_accounts(status,created_at);
CREATE INDEX IF NOT EXISTS idx_delivery_customer_phone ON delivery_customer_accounts(phone);

CREATE TABLE IF NOT EXISTS delivery_customer_auth_tokens (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  account_id INTEGER NOT NULL,
  purpose TEXT NOT NULL,
  token_hash TEXT NOT NULL UNIQUE,
  expires_at TEXT NOT NULL,
  used_at TEXT NULL,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (account_id) REFERENCES delivery_customer_accounts(id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_delivery_customer_token_lookup ON delivery_customer_auth_tokens(purpose,token_hash,expires_at);
CREATE INDEX IF NOT EXISTS idx_delivery_customer_token_account ON delivery_customer_auth_tokens(account_id,purpose,created_at);

CREATE TABLE IF NOT EXISTS delivery_customer_sessions (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  account_id INTEGER NOT NULL,
  token_hash TEXT NOT NULL UNIQUE,
  device_name TEXT NULL,
  user_agent TEXT NULL,
  ip_hash TEXT NULL,
  expires_at TEXT NOT NULL,
  last_seen_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  revoked_at TEXT NULL,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (account_id) REFERENCES delivery_customer_accounts(id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_delivery_customer_session_account ON delivery_customer_sessions(account_id,revoked_at,expires_at);

CREATE TABLE IF NOT EXISTS delivery_customer_addresses (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  account_id INTEGER NOT NULL,
  label TEXT NOT NULL DEFAULT 'Casa',
  recipient_name TEXT NULL,
  phone TEXT NULL,
  postal_code TEXT NULL,
  street TEXT NOT NULL,
  number TEXT NOT NULL,
  complement TEXT NULL,
  neighborhood TEXT NULL,
  city TEXT NOT NULL,
  state TEXT NOT NULL,
  reference TEXT NULL,
  latitude REAL NULL,
  longitude REAL NULL,
  is_default INTEGER NOT NULL DEFAULT 0,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (account_id) REFERENCES delivery_customer_accounts(id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_delivery_customer_address_account ON delivery_customer_addresses(account_id,is_default,id);

CREATE TABLE IF NOT EXISTS delivery_customer_favorites (
  account_id INTEGER NOT NULL,
  tenant_id INTEGER NOT NULL,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (account_id,tenant_id),
  FOREIGN KEY (account_id) REFERENCES delivery_customer_accounts(id) ON DELETE CASCADE,
  FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS delivery_customer_order_links (
  account_id INTEGER NOT NULL,
  tenant_id INTEGER NOT NULL,
  order_id INTEGER NOT NULL UNIQUE,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (account_id,order_id),
  FOREIGN KEY (account_id) REFERENCES delivery_customer_accounts(id) ON DELETE CASCADE,
  FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
  FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_delivery_customer_orders ON delivery_customer_order_links(account_id,created_at);

CREATE TABLE IF NOT EXISTS delivery_customer_reviews (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  account_id INTEGER NOT NULL,
  tenant_id INTEGER NOT NULL,
  order_id INTEGER NOT NULL UNIQUE,
  rating INTEGER NOT NULL,
  comment TEXT NULL,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (account_id) REFERENCES delivery_customer_accounts(id) ON DELETE CASCADE,
  FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
  FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_delivery_customer_review_tenant ON delivery_customer_reviews(tenant_id,rating,created_at);

CREATE TABLE IF NOT EXISTS delivery_customer_push_devices (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  account_id INTEGER NOT NULL,
  platform TEXT NOT NULL DEFAULT 'android',
  push_token TEXT NOT NULL UNIQUE,
  device_id_hash TEXT NULL,
  active INTEGER NOT NULL DEFAULT 1,
  last_seen_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (account_id) REFERENCES delivery_customer_accounts(id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_delivery_customer_push_account ON delivery_customer_push_devices(account_id,active);
