PRAGMA foreign_keys = ON;

CREATE TABLE financial_recurring_templates (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  tenant_id INTEGER NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
  unit_id INTEGER NULL REFERENCES operating_units(id) ON DELETE SET NULL,
  event_id INTEGER NULL REFERENCES events(id) ON DELETE SET NULL,
  account_id INTEGER NULL REFERENCES financial_accounts(id) ON DELETE SET NULL,
  category_id INTEGER NULL REFERENCES financial_categories(id) ON DELETE SET NULL,
  direction TEXT NOT NULL,
  description TEXT NOT NULL,
  amount_cents INTEGER NOT NULL,
  frequency TEXT NOT NULL,
  interval_count INTEGER NOT NULL DEFAULT 1,
  anchor_day INTEGER NULL,
  next_due_date TEXT NOT NULL,
  end_date TEXT NULL,
  active INTEGER NOT NULL DEFAULT 1,
  created_by INTEGER NULL REFERENCES users(id) ON DELETE SET NULL,
  last_generated_at TEXT NULL,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX idx_fin_recurring_due ON financial_recurring_templates(tenant_id,active,next_due_date);
