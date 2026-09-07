ALTER TABLE purchase_orders ADD COLUMN payment_method VARCHAR(30) NOT NULL DEFAULT 'other' AFTER total_cents;
ALTER TABLE purchase_orders ADD COLUMN first_due_date DATE NULL AFTER payment_method;
ALTER TABLE purchase_orders ADD COLUMN installments_count INT NOT NULL DEFAULT 1 AFTER first_due_date;
ALTER TABLE purchase_orders ADD COLUMN installment_interval_days INT NOT NULL DEFAULT 30 AFTER installments_count;
ALTER TABLE purchase_orders ADD COLUMN finance_generated_at DATETIME NULL AFTER received_at;

CREATE TABLE purchase_payment_installments (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id BIGINT UNSIGNED NOT NULL,
  purchase_order_id BIGINT UNSIGNED NOT NULL,
  installment_no INT NOT NULL,
  amount_cents BIGINT NOT NULL,
  due_date DATE NOT NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'open',
  financial_entry_id BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  settled_at DATETIME NULL,
  CONSTRAINT fk_purchase_installment_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
  CONSTRAINT fk_purchase_installment_order FOREIGN KEY (purchase_order_id) REFERENCES purchase_orders(id) ON DELETE CASCADE,
  CONSTRAINT fk_purchase_installment_finance FOREIGN KEY (financial_entry_id) REFERENCES financial_entries(id) ON DELETE SET NULL,
  UNIQUE KEY uq_purchase_installment (purchase_order_id,installment_no),
  INDEX idx_purchase_installment_due (tenant_id,status,due_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
