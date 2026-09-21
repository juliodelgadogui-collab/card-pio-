CREATE TABLE IF NOT EXISTS whatsapp_connections (
  tenant_id INTEGER PRIMARY KEY,
  provider TEXT NOT NULL DEFAULT 'wppconnect',
  session_key TEXT NOT NULL UNIQUE,
  status TEXT NOT NULL DEFAULT 'disconnected',
  phone_number TEXT NULL,
  automation_enabled INTEGER NOT NULL DEFAULT 0,
  automation_enabled_at TEXT NULL,
  last_connected_at TEXT NULL,
  last_seen_at TEXT NULL,
  last_error TEXT NULL,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_whatsapp_connection_status ON whatsapp_connections(status,updated_at);

CREATE TABLE IF NOT EXISTS whatsapp_event_templates (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  tenant_id INTEGER NOT NULL,
  event_type TEXT NOT NULL,
  enabled INTEGER NOT NULL DEFAULT 0,
  message_template TEXT NOT NULL,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
  UNIQUE (tenant_id,event_type)
);
CREATE INDEX IF NOT EXISTS idx_whatsapp_template_enabled ON whatsapp_event_templates(tenant_id,enabled,event_type);

CREATE TABLE IF NOT EXISTS whatsapp_outbox (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  tenant_id INTEGER NOT NULL,
  order_id INTEGER NULL,
  event_type TEXT NOT NULL,
  recipient TEXT NOT NULL,
  message_text TEXT NOT NULL,
  status TEXT NOT NULL DEFAULT 'queued',
  attempt_count INTEGER NOT NULL DEFAULT 0,
  max_attempts INTEGER NOT NULL DEFAULT 5,
  available_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  locked_at TEXT NULL,
  sent_at TEXT NULL,
  external_message_id TEXT NULL,
  last_error TEXT NULL,
  idempotency_key TEXT NOT NULL UNIQUE,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
  FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE SET NULL
);
CREATE INDEX IF NOT EXISTS idx_whatsapp_outbox_dispatch ON whatsapp_outbox(status,available_at,id);
CREATE INDEX IF NOT EXISTS idx_whatsapp_outbox_tenant ON whatsapp_outbox(tenant_id,created_at);
