CREATE TABLE IF NOT EXISTS delivery_cash_handoffs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id INTEGER NOT NULL,
    work_shift_id INTEGER NOT NULL,
    delivery_user_id INTEGER NOT NULL,
    amount_cents INTEGER NOT NULL,
    status TEXT NOT NULL DEFAULT 'pending' CHECK(status IN ('pending','confirmed','cancelled')),
    token_hash TEXT NOT NULL UNIQUE,
    confirmed_by INTEGER NULL,
    requested_at TEXT DEFAULT CURRENT_TIMESTAMP,
    confirmed_at TEXT NULL,
    notes TEXT NULL,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (work_shift_id) REFERENCES work_shifts(id) ON DELETE CASCADE,
    FOREIGN KEY (delivery_user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (confirmed_by) REFERENCES users(id) ON DELETE SET NULL
);
CREATE INDEX IF NOT EXISTS idx_delivery_handoff_shift ON delivery_cash_handoffs (tenant_id,work_shift_id,status);
