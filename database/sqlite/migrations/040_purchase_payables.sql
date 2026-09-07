PRAGMA foreign_keys = ON;

ALTER TABLE purchase_orders ADD COLUMN payment_method TEXT NOT NULL DEFAULT 'other';
ALTER TABLE purchase_orders ADD COLUMN first_due_date TEXT NULL;
ALTER TABLE purchase_orders ADD COLUMN installments_count INTEGER NOT NULL DEFAULT 1;
ALTER TABLE purchase_orders ADD COLUMN installment_interval_days INTEGER NOT NULL DEFAULT 30;
ALTER TABLE purchase_orders ADD COLUMN finance_generated_at TEXT NULL;

CREATE TABLE purchase_payment_installments (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  tenant_id INTEGER NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
  purchase_order_id INTEGER NOT NULL REFERENCES purchase_orders(id) ON DELETE CASCADE,
  installment_no INTEGER NOT NULL,
  amount_cents INTEGER NOT NULL,
  due_date TEXT NOT NULL,
  status TEXT NOT NULL DEFAULT 'open',
  financial_entry_id INTEGER NULL REFERENCES financial_entries(id) ON DELETE SET NULL,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  settled_at TEXT NULL,
  UNIQUE (purchase_order_id,installment_no)
);
CREATE INDEX idx_purchase_installment_due ON purchase_payment_installments(tenant_id,status,due_date);
