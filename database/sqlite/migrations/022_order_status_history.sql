CREATE TABLE order_status_history (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  tenant_id INTEGER NOT NULL,
  order_id INTEGER NOT NULL,
  user_id INTEGER NULL,
  from_status TEXT NULL,
  to_status TEXT NOT NULL,
  source TEXT NOT NULL,
  notes TEXT NULL,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
  FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
);
CREATE INDEX idx_order_history_timeline ON order_status_history(tenant_id,order_id,id);
CREATE INDEX idx_order_history_user ON order_status_history(tenant_id,user_id,created_at);

INSERT INTO order_status_history (tenant_id,order_id,user_id,from_status,to_status,source,notes,created_at)
SELECT o.tenant_id,o.id,o.created_by,NULL,o.status,'migration','Estado existente importado para a linha do tempo.',o.created_at
FROM orders o
WHERE NOT EXISTS (SELECT 1 FROM order_status_history h WHERE h.tenant_id=o.tenant_id AND h.order_id=o.id);
