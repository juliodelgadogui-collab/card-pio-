CREATE TABLE IF NOT EXISTS delivery_customer_accounts (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(160) NOT NULL,
  email VARCHAR(190) NOT NULL UNIQUE,
  phone VARCHAR(40) NULL,
  password_hash VARCHAR(255) NOT NULL,
  email_verified_at DATETIME NULL,
  status VARCHAR(40) NOT NULL DEFAULT 'pending_verification',
  marketing_opt_in TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_delivery_customer_status(status,created_at),
  INDEX idx_delivery_customer_phone(phone)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS delivery_customer_auth_tokens (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  account_id BIGINT UNSIGNED NOT NULL,
  purpose VARCHAR(40) NOT NULL,
  token_hash CHAR(64) NOT NULL UNIQUE,
  expires_at DATETIME NOT NULL,
  used_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_delivery_auth_account FOREIGN KEY(account_id) REFERENCES delivery_customer_accounts(id) ON DELETE CASCADE,
  INDEX idx_delivery_customer_token_lookup(purpose,token_hash,expires_at),
  INDEX idx_delivery_customer_token_account(account_id,purpose,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS delivery_customer_sessions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  account_id BIGINT UNSIGNED NOT NULL,
  token_hash CHAR(64) NOT NULL UNIQUE,
  device_name VARCHAR(160) NULL,
  user_agent VARCHAR(500) NULL,
  ip_hash CHAR(64) NULL,
  expires_at DATETIME NOT NULL,
  last_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  revoked_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_delivery_session_account FOREIGN KEY(account_id) REFERENCES delivery_customer_accounts(id) ON DELETE CASCADE,
  INDEX idx_delivery_customer_session_account(account_id,revoked_at,expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS delivery_customer_addresses (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  account_id BIGINT UNSIGNED NOT NULL,
  label VARCHAR(80) NOT NULL DEFAULT 'Casa',
  recipient_name VARCHAR(160) NULL,
  phone VARCHAR(40) NULL,
  postal_code VARCHAR(16) NULL,
  street VARCHAR(220) NOT NULL,
  number VARCHAR(40) NOT NULL,
  complement VARCHAR(180) NULL,
  neighborhood VARCHAR(160) NULL,
  city VARCHAR(160) NOT NULL,
  state CHAR(2) NOT NULL,
  reference VARCHAR(300) NULL,
  latitude DECIMAL(10,7) NULL,
  longitude DECIMAL(10,7) NULL,
  is_default TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_delivery_address_account FOREIGN KEY(account_id) REFERENCES delivery_customer_accounts(id) ON DELETE CASCADE,
  INDEX idx_delivery_customer_address_account(account_id,is_default,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS delivery_customer_favorites (
  account_id BIGINT UNSIGNED NOT NULL,
  tenant_id BIGINT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY(account_id,tenant_id),
  CONSTRAINT fk_delivery_favorite_account FOREIGN KEY(account_id) REFERENCES delivery_customer_accounts(id) ON DELETE CASCADE,
  CONSTRAINT fk_delivery_favorite_tenant FOREIGN KEY(tenant_id) REFERENCES tenants(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS delivery_customer_order_links (
  account_id BIGINT UNSIGNED NOT NULL,
  tenant_id BIGINT UNSIGNED NOT NULL,
  order_id BIGINT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY(account_id,order_id),
  UNIQUE KEY uq_delivery_customer_order(order_id),
  CONSTRAINT fk_delivery_order_account FOREIGN KEY(account_id) REFERENCES delivery_customer_accounts(id) ON DELETE CASCADE,
  CONSTRAINT fk_delivery_order_tenant FOREIGN KEY(tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
  CONSTRAINT fk_delivery_order_order FOREIGN KEY(order_id) REFERENCES orders(id) ON DELETE CASCADE,
  INDEX idx_delivery_customer_orders(account_id,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS delivery_customer_reviews (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  account_id BIGINT UNSIGNED NOT NULL,
  tenant_id BIGINT UNSIGNED NOT NULL,
  order_id BIGINT UNSIGNED NOT NULL UNIQUE,
  rating TINYINT UNSIGNED NOT NULL,
  comment VARCHAR(1000) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_delivery_review_account FOREIGN KEY(account_id) REFERENCES delivery_customer_accounts(id) ON DELETE CASCADE,
  CONSTRAINT fk_delivery_review_tenant FOREIGN KEY(tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
  CONSTRAINT fk_delivery_review_order FOREIGN KEY(order_id) REFERENCES orders(id) ON DELETE CASCADE,
  INDEX idx_delivery_customer_review_tenant(tenant_id,rating,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS delivery_customer_push_devices (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  account_id BIGINT UNSIGNED NOT NULL,
  platform VARCHAR(30) NOT NULL DEFAULT 'android',
  push_token VARCHAR(512) NOT NULL,
  device_id_hash CHAR(64) NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  last_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_delivery_customer_push(push_token(190)),
  CONSTRAINT fk_delivery_push_account FOREIGN KEY(account_id) REFERENCES delivery_customer_accounts(id) ON DELETE CASCADE,
  INDEX idx_delivery_customer_push_account(account_id,active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
