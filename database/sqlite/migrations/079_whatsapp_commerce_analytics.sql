CREATE TABLE IF NOT EXISTS whatsapp_commerce_events (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  tenant_id INTEGER NOT NULL,
  conversation_id INTEGER NULL,
  order_id INTEGER NULL,
  event_type TEXT NOT NULL,
  value_cents INTEGER NOT NULL DEFAULT 0,
  metadata TEXT NULL,
  idempotency_key TEXT NULL,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE
);

CREATE UNIQUE INDEX IF NOT EXISTS uq_whatsapp_commerce_events_key
  ON whatsapp_commerce_events(tenant_id,idempotency_key);
CREATE INDEX IF NOT EXISTS idx_whatsapp_commerce_events_tenant_date
  ON whatsapp_commerce_events(tenant_id,created_at);
CREATE INDEX IF NOT EXISTS idx_whatsapp_commerce_events_type_date
  ON whatsapp_commerce_events(tenant_id,event_type,created_at);
CREATE INDEX IF NOT EXISTS idx_whatsapp_commerce_events_order
  ON whatsapp_commerce_events(tenant_id,order_id);
