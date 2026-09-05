CREATE TABLE IF NOT EXISTS user_permission_overrides (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id INTEGER NOT NULL,
    user_id INTEGER NOT NULL,
    permission TEXT NOT NULL,
    allowed INTEGER NOT NULL DEFAULT 1,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (tenant_id,user_id,permission),
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_user_permission_user ON user_permission_overrides (tenant_id,user_id);

CREATE TABLE IF NOT EXISTS work_shifts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id INTEGER NOT NULL,
    user_id INTEGER NOT NULL,
    mode TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT 'open' CHECK(status IN ('open','closed')),
    device_hash TEXT NULL,
    opening_notes TEXT NULL,
    closing_notes TEXT NULL,
    started_at TEXT DEFAULT CURRENT_TIMESTAMP,
    ended_at TEXT NULL,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_work_shift_user ON work_shifts (tenant_id,user_id,status);
CREATE INDEX IF NOT EXISTS idx_work_shift_mode ON work_shifts (tenant_id,mode,status);

CREATE TABLE IF NOT EXISTS work_shift_movements (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id INTEGER NOT NULL,
    work_shift_id INTEGER NOT NULL,
    user_id INTEGER NOT NULL,
    order_id INTEGER NULL,
    payment_id INTEGER NULL,
    type TEXT NOT NULL,
    method TEXT NULL,
    direction TEXT NOT NULL CHECK(direction IN ('in','out')),
    amount_cents INTEGER NOT NULL DEFAULT 0,
    notes TEXT NULL,
    idempotency_key TEXT NOT NULL,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (tenant_id,idempotency_key),
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (work_shift_id) REFERENCES work_shifts(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE SET NULL,
    FOREIGN KEY (payment_id) REFERENCES payments(id) ON DELETE SET NULL
);
CREATE INDEX IF NOT EXISTS idx_work_shift_movement_shift ON work_shift_movements (tenant_id,work_shift_id,created_at);
