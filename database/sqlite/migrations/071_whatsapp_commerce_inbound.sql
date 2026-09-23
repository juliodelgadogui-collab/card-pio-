ALTER TABLE whatsapp_connections ADD COLUMN commerce_enabled INTEGER NOT NULL DEFAULT 0;

CREATE TABLE IF NOT EXISTS whatsapp_conversations (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  tenant_id INTEGER NOT NULL,
  phone TEXT NOT NULL,
  customer_id INTEGER NULL,
  draft_order_id INTEGER NULL,
  active_order_id INTEGER NULL,
  mode TEXT NOT NULL DEFAULT 'auto',
  state TEXT NOT NULL DEFAULT 'IDLE',
  context_json TEXT NULL,
  assigned_user_id INTEGER NULL,
  last_inbound_at TEXT NULL,
  last_outbound_at TEXT NULL,
  last_activity_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE (tenant_id,phone),
  FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_wa_conversation_mode ON whatsapp_conversations(tenant_id,mode,last_activity_at);
CREATE INDEX IF NOT EXISTS idx_wa_conversation_customer ON whatsapp_conversations(tenant_id,customer_id);
CREATE INDEX IF NOT EXISTS idx_wa_conversation_order ON whatsapp_conversations(tenant_id,active_order_id);

CREATE TABLE IF NOT EXISTS whatsapp_messages (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  tenant_id INTEGER NOT NULL,
  conversation_id INTEGER NOT NULL,
  provider_message_id TEXT NULL,
  outbox_id INTEGER NULL,
  direction TEXT NOT NULL,
  message_type TEXT NOT NULL DEFAULT 'text',
  message_text TEXT NULL,
  payload_json TEXT NULL,
  status TEXT NOT NULL DEFAULT 'received',
  provider_created_at TEXT NULL,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE (tenant_id,provider_message_id),
  UNIQUE (outbox_id),
  FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
  FOREIGN KEY (conversation_id) REFERENCES whatsapp_conversations(id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_wa_message_conversation ON whatsapp_messages(tenant_id,conversation_id,created_at);
CREATE INDEX IF NOT EXISTS idx_wa_message_status ON whatsapp_messages(tenant_id,status,created_at);
