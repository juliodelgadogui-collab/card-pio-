CREATE TABLE IF NOT EXISTS cash_sessions (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  tenant_id INTEGER NOT NULL,
  user_id INTEGER NOT NULL,
  status TEXT NOT NULL DEFAULT 'open' CHECK(status IN ('open','closed')),
  opening_cash_cents INTEGER NOT NULL DEFAULT 0,
  closing_cash_cents INTEGER NULL,
  expected_cash_cents INTEGER NULL,
  difference_cents INTEGER NULL,
  opening_notes TEXT NULL,
  closing_notes TEXT NULL,
  opened_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  closed_at TEXT NULL,
  FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT
);
CREATE INDEX IF NOT EXISTS idx_cash_sessions_open ON cash_sessions(tenant_id,user_id,status,opened_at);
CREATE INDEX IF NOT EXISTS idx_cash_sessions_tenant_period ON cash_sessions(tenant_id,opened_at,closed_at);

CREATE TABLE IF NOT EXISTS cash_movements (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  tenant_id INTEGER NOT NULL,
  cash_session_id INTEGER NOT NULL,
  user_id INTEGER NOT NULL,
  order_id INTEGER NULL,
  payment_id INTEGER NULL,
  type TEXT NOT NULL CHECK(type IN ('sale','supply','withdrawal','refund','adjustment')),
  method TEXT NOT NULL DEFAULT 'cash' CHECK(method IN ('cash','pix','card','other')),
  direction TEXT NOT NULL CHECK(direction IN ('in','out')),
  amount_cents INTEGER NOT NULL,
  notes TEXT NULL,
  idempotency_key TEXT NOT NULL,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
  FOREIGN KEY (cash_session_id) REFERENCES cash_sessions(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT,
  FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE SET NULL,
  FOREIGN KEY (payment_id) REFERENCES payments(id) ON DELETE SET NULL,
  UNIQUE(tenant_id,idempotency_key)
);
CREATE INDEX IF NOT EXISTS idx_cash_movements_session ON cash_movements(cash_session_id,created_at);
CREATE INDEX IF NOT EXISTS idx_cash_movements_payment ON cash_movements(tenant_id,payment_id);
