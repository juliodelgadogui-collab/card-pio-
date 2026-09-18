ALTER TABLE orders
  ADD COLUMN order_source VARCHAR(40) NOT NULL DEFAULT 'EVENTMENU_OWN' AFTER channel,
  ADD COLUMN marketplace_campaign_code VARCHAR(80) NULL AFTER order_source;
CREATE INDEX idx_orders_source_created ON orders (tenant_id,order_source,created_at);

CREATE TABLE marketplace_tenant_settings (
  tenant_id BIGINT UNSIGNED PRIMARY KEY,
  participates TINYINT(1) NOT NULL DEFAULT 0,
  status VARCHAR(24) NOT NULL DEFAULT 'inactive',
  joined_at DATETIME NULL,
  city VARCHAR(120) NULL,
  state CHAR(2) NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_marketplace_tenant_setting_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
  INDEX idx_marketplace_tenant_status (participates,status,city,state)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE marketplace_commission_rules (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(160) NOT NULL,
  scope_type VARCHAR(24) NOT NULL,
  tenant_id BIGINT UNSIGNED NULL,
  city VARCHAR(120) NULL,
  state CHAR(2) NULL,
  plan_code VARCHAR(60) NULL,
  campaign_code VARCHAR(80) NULL,
  rate_bps INT UNSIGNED NOT NULL,
  priority INT NOT NULL DEFAULT 0,
  starts_at DATETIME NULL,
  ends_at DATETIME NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_by BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_marketplace_rule_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
  CONSTRAINT fk_marketplace_rule_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
  INDEX idx_marketplace_rule_match (active,scope_type,tenant_id,city,state,plan_code,campaign_code,starts_at,ends_at,priority)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO marketplace_commission_rules (name,scope_type,rate_bps,priority,active)
SELECT 'Taxa padrão EventMenu Delivery','default',400,0,1
WHERE NOT EXISTS (SELECT 1 FROM marketplace_commission_rules WHERE scope_type='default' AND active=1);

CREATE TABLE marketplace_order_commissions (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id BIGINT UNSIGNED NOT NULL,
  unit_id BIGINT UNSIGNED NULL,
  order_id BIGINT UNSIGNED NOT NULL,
  rule_id BIGINT UNSIGNED NULL,
  order_source VARCHAR(40) NOT NULL,
  products_gross_cents INT UNSIGNED NOT NULL DEFAULT 0,
  product_discount_cents INT UNSIGNED NOT NULL DEFAULT 0,
  excluded_adjustments_cents INT UNSIGNED NOT NULL DEFAULT 0,
  calculation_base_cents INT UNSIGNED NOT NULL DEFAULT 0,
  commission_bps INT UNSIGNED NOT NULL DEFAULT 0,
  commission_cents INT UNSIGNED NOT NULL DEFAULT 0,
  status VARCHAR(24) NOT NULL DEFAULT 'provisioned',
  rule_snapshot TEXT NOT NULL,
  invoice_item_id BIGINT UNSIGNED NULL,
  due_at DATETIME NULL,
  invoiced_at DATETIME NULL,
  paid_at DATETIME NULL,
  reversed_at DATETIME NULL,
  reversal_reason VARCHAR(500) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_marketplace_commission_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
  CONSTRAINT fk_marketplace_commission_unit FOREIGN KEY (unit_id) REFERENCES operating_units(id) ON DELETE SET NULL,
  CONSTRAINT fk_marketplace_commission_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE RESTRICT,
  CONSTRAINT fk_marketplace_commission_rule FOREIGN KEY (rule_id) REFERENCES marketplace_commission_rules(id) ON DELETE SET NULL,
  UNIQUE KEY uq_marketplace_commission_order (order_id),
  INDEX idx_marketplace_commission_status (tenant_id,status,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE platform_invoices (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id BIGINT UNSIGNED NOT NULL,
  period_start DATE NOT NULL,
  period_end DATE NOT NULL,
  issued_at DATETIME NULL,
  due_at DATETIME NULL,
  status VARCHAR(24) NOT NULL DEFAULT 'draft',
  subtotal_cents INT NOT NULL DEFAULT 0,
  credits_cents INT NOT NULL DEFAULT 0,
  total_cents INT NOT NULL DEFAULT 0,
  paid_cents INT NOT NULL DEFAULT 0,
  paid_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_platform_invoice_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
  UNIQUE KEY uq_platform_invoice_period (tenant_id,period_start,period_end),
  INDEX idx_platform_invoice_status (status,due_at,tenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE platform_invoice_items (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  invoice_id BIGINT UNSIGNED NOT NULL,
  tenant_id BIGINT UNSIGNED NOT NULL,
  item_type VARCHAR(40) NOT NULL,
  order_id BIGINT UNSIGNED NULL,
  marketplace_commission_id BIGINT UNSIGNED NULL,
  description VARCHAR(500) NOT NULL,
  quantity DECIMAL(12,3) NOT NULL DEFAULT 1,
  unit_amount_cents INT NOT NULL,
  amount_cents INT NOT NULL,
  snapshot_json TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_platform_invoice_item_invoice FOREIGN KEY (invoice_id) REFERENCES platform_invoices(id) ON DELETE CASCADE,
  CONSTRAINT fk_platform_invoice_item_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
  CONSTRAINT fk_platform_invoice_item_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE SET NULL,
  CONSTRAINT fk_platform_invoice_item_commission FOREIGN KEY (marketplace_commission_id) REFERENCES marketplace_order_commissions(id) ON DELETE SET NULL,
  UNIQUE KEY uq_platform_invoice_marketplace_commission (marketplace_commission_id),
  INDEX idx_platform_invoice_item_invoice (invoice_id,item_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE marketplace_order_commissions
  ADD CONSTRAINT fk_marketplace_commission_invoice_item FOREIGN KEY (invoice_item_id) REFERENCES platform_invoice_items(id) ON DELETE SET NULL;

CREATE TABLE marketplace_promotion_accounts (
  tenant_id BIGINT UNSIGNED PRIMARY KEY,
  participates TINYINT(1) NOT NULL DEFAULT 0,
  balance_cents INT NOT NULL DEFAULT 0,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_marketplace_promotion_account_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE marketplace_promotion_ledger (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id BIGINT UNSIGNED NOT NULL,
  order_id BIGINT UNSIGNED NULL,
  entry_type VARCHAR(40) NOT NULL,
  amount_cents INT NOT NULL,
  description VARCHAR(500) NOT NULL,
  reference_key VARCHAR(190) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_marketplace_promotion_ledger_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
  CONSTRAINT fk_marketplace_promotion_ledger_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE SET NULL,
  UNIQUE KEY uq_marketplace_promotion_reference (tenant_id,reference_key),
  INDEX idx_marketplace_promotion_created (tenant_id,entry_type,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
