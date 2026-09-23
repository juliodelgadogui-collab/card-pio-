CREATE TABLE IF NOT EXISTS delivery_customer_payment_preferences (
  order_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
  account_id BIGINT UNSIGNED NOT NULL,
  tenant_id BIGINT UNSIGNED NOT NULL,
  method VARCHAR(30) NOT NULL,
  change_for_cents INT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_delivery_payment_pref_order FOREIGN KEY(order_id) REFERENCES orders(id) ON DELETE CASCADE,
  CONSTRAINT fk_delivery_payment_pref_account FOREIGN KEY(account_id) REFERENCES delivery_customer_accounts(id) ON DELETE CASCADE,
  CONSTRAINT fk_delivery_payment_pref_tenant FOREIGN KEY(tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
  INDEX idx_delivery_customer_payment_pref_account(account_id,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
