ALTER TABLE orders ADD COLUMN coupon_discount_cents INT NOT NULL DEFAULT 0 AFTER discount_cents;
ALTER TABLE orders ADD COLUMN manual_discount_cents INT NOT NULL DEFAULT 0 AFTER coupon_discount_cents;
ALTER TABLE orders ADD COLUMN surcharge_cents INT NOT NULL DEFAULT 0 AFTER delivery_fee_cents;
UPDATE orders SET coupon_discount_cents=discount_cents WHERE coupon_id IS NOT NULL AND discount_cents>0;
UPDATE orders SET manual_discount_cents=discount_cents WHERE coupon_id IS NULL AND discount_cents>0;

ALTER TABLE order_discount_requests ADD COLUMN discount_type VARCHAR(20) NOT NULL DEFAULT 'fixed' AFTER requested_cents;
ALTER TABLE order_discount_requests ADD COLUMN requested_bps INT NOT NULL DEFAULT 0 AFTER discount_type;
ALTER TABLE order_discount_requests ADD COLUMN applied_cents INT NOT NULL DEFAULT 0 AFTER requested_bps;
ALTER TABLE order_discount_requests ADD COLUMN auto_approved TINYINT(1) NOT NULL DEFAULT 0 AFTER applied_cents;
ALTER TABLE order_discount_requests ADD COLUMN unit_id BIGINT UNSIGNED NULL AFTER order_id;
ALTER TABLE order_discount_requests ADD CONSTRAINT fk_discount_request_unit FOREIGN KEY (unit_id) REFERENCES operating_units(id) ON DELETE SET NULL;
CREATE INDEX idx_discount_requests_unit ON order_discount_requests(tenant_id,unit_id,status,created_at);

CREATE TABLE discount_policies (
  tenant_id BIGINT UNSIGNED PRIMARY KEY,
  cashier_auto_bps INT NOT NULL DEFAULT 500,
  manager_auto_bps INT NOT NULL DEFAULT 2000,
  admin_auto_bps INT NOT NULL DEFAULT 10000,
  require_reason TINYINT(1) NOT NULL DEFAULT 1,
  allow_percentage TINYINT(1) NOT NULL DEFAULT 1,
  updated_by BIGINT UNSIGNED NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_discount_policy_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
  CONSTRAINT fk_discount_policy_user FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE order_price_adjustments (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id BIGINT UNSIGNED NOT NULL,
  order_id BIGINT UNSIGNED NOT NULL,
  order_item_id BIGINT UNSIGNED NULL,
  direction VARCHAR(20) NOT NULL,
  adjustment_type VARCHAR(40) NOT NULL,
  label VARCHAR(160) NOT NULL,
  amount_cents INT NOT NULL,
  percent_bps INT NOT NULL DEFAULT 0,
  request_id BIGINT UNSIGNED NULL,
  authorized_by BIGINT UNSIGNED NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  idempotency_key VARCHAR(120) NOT NULL,
  metadata JSON NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_price_adjustment_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
  CONSTRAINT fk_price_adjustment_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
  CONSTRAINT fk_price_adjustment_item FOREIGN KEY (order_item_id) REFERENCES order_items(id) ON DELETE CASCADE,
  CONSTRAINT fk_price_adjustment_request FOREIGN KEY (request_id) REFERENCES order_discount_requests(id) ON DELETE SET NULL,
  CONSTRAINT fk_price_adjustment_user FOREIGN KEY (authorized_by) REFERENCES users(id) ON DELETE SET NULL,
  UNIQUE KEY uq_price_adjustment_idempotency (tenant_id,idempotency_key),
  KEY idx_price_adjustment_order (tenant_id,order_id,active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE financial_accounts (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id BIGINT UNSIGNED NOT NULL,
  unit_id BIGINT UNSIGNED NULL,
  name VARCHAR(120) NOT NULL,
  account_type VARCHAR(30) NOT NULL,
  provider VARCHAR(40) NULL,
  opening_balance_cents BIGINT NOT NULL DEFAULT 0,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_fin_account_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
  CONSTRAINT fk_fin_account_unit FOREIGN KEY (unit_id) REFERENCES operating_units(id) ON DELETE SET NULL,
  KEY idx_fin_account_tenant (tenant_id,active,account_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE financial_categories (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id BIGINT UNSIGNED NOT NULL,
  name VARCHAR(120) NOT NULL,
  direction VARCHAR(10) NOT NULL,
  dre_group VARCHAR(40) NOT NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_fin_category_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
  UNIQUE KEY uq_fin_category_name (tenant_id,name,direction)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE financial_entries (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id BIGINT UNSIGNED NOT NULL,
  unit_id BIGINT UNSIGNED NULL,
  event_id BIGINT UNSIGNED NULL,
  order_id BIGINT UNSIGNED NULL,
  payment_id BIGINT UNSIGNED NULL,
  purchase_order_id BIGINT UNSIGNED NULL,
  supplier_id BIGINT UNSIGNED NULL,
  account_id BIGINT UNSIGNED NULL,
  category_id BIGINT UNSIGNED NULL,
  direction VARCHAR(10) NOT NULL,
  entry_type VARCHAR(40) NOT NULL,
  description VARCHAR(255) NOT NULL,
  gross_cents BIGINT NOT NULL,
  fee_cents BIGINT NOT NULL DEFAULT 0,
  net_cents BIGINT NOT NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'open',
  competence_date DATE NOT NULL,
  due_date DATE NULL,
  settled_at DATETIME NULL,
  external_reference VARCHAR(160) NULL,
  idempotency_key VARCHAR(160) NOT NULL,
  metadata JSON NULL,
  created_by BIGINT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_fin_entry_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
  CONSTRAINT fk_fin_entry_unit FOREIGN KEY (unit_id) REFERENCES operating_units(id) ON DELETE SET NULL,
  CONSTRAINT fk_fin_entry_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE SET NULL,
  CONSTRAINT fk_fin_entry_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE SET NULL,
  CONSTRAINT fk_fin_entry_payment FOREIGN KEY (payment_id) REFERENCES payments(id) ON DELETE SET NULL,
  CONSTRAINT fk_fin_entry_purchase FOREIGN KEY (purchase_order_id) REFERENCES purchase_orders(id) ON DELETE SET NULL,
  CONSTRAINT fk_fin_entry_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers(id) ON DELETE SET NULL,
  CONSTRAINT fk_fin_entry_account FOREIGN KEY (account_id) REFERENCES financial_accounts(id) ON DELETE SET NULL,
  CONSTRAINT fk_fin_entry_category FOREIGN KEY (category_id) REFERENCES financial_categories(id) ON DELETE SET NULL,
  CONSTRAINT fk_fin_entry_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
  UNIQUE KEY uq_fin_entry_idempotency (tenant_id,idempotency_key),
  KEY idx_fin_entry_scope (tenant_id,unit_id,competence_date,direction,status),
  KEY idx_fin_entry_due (tenant_id,status,due_date),
  KEY idx_fin_entry_event (tenant_id,event_id,competence_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE payment_fee_rules (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id BIGINT UNSIGNED NOT NULL,
  provider VARCHAR(40) NOT NULL,
  payment_method VARCHAR(40) NOT NULL DEFAULT '*',
  percent_bps INT NOT NULL DEFAULT 0,
  fixed_cents INT NOT NULL DEFAULT 0,
  settlement_days INT NOT NULL DEFAULT 0,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_fee_rule_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
  UNIQUE KEY uq_fee_rule (tenant_id,provider,payment_method)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
