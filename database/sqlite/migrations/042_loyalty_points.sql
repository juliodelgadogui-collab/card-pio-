CREATE TABLE IF NOT EXISTS customer_points_reservations (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  tenant_id INTEGER NOT NULL,
  customer_id INTEGER NOT NULL,
  order_id INTEGER NOT NULL,
  points INTEGER NOT NULL CHECK(points > 0),
  discount_cents INTEGER NOT NULL CHECK(discount_cents > 0),
  status TEXT NOT NULL DEFAULT 'reserved' CHECK(status IN ('reserved','redeemed','released','restored')),
  redeemed_at TEXT NULL,
  released_at TEXT NULL,
  restored_at TEXT NULL,
  created_at TEXT DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
  FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE,
  FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
  UNIQUE (tenant_id, order_id)
);
CREATE INDEX IF NOT EXISTS idx_points_reservation_customer ON customer_points_reservations (tenant_id, customer_id, status);
