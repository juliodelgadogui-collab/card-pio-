CREATE TABLE IF NOT EXISTS payment_collection_contexts (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  tenant_id INTEGER NOT NULL,
  payment_id INTEGER NOT NULL,
  user_id INTEGER NOT NULL,
  work_shift_id INTEGER NULL,
  method TEXT NOT NULL,
  source TEXT NOT NULL,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
  FOREIGN KEY (payment_id) REFERENCES payments(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (work_shift_id) REFERENCES work_shifts(id) ON DELETE SET NULL,
  UNIQUE (tenant_id,payment_id)
);

CREATE INDEX IF NOT EXISTS idx_payment_collection_shift ON payment_collection_contexts(tenant_id,work_shift_id,user_id);
