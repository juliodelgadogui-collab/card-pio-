CREATE TABLE IF NOT EXISTS whatsapp_communications (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  tenant_id INTEGER NOT NULL,
  title TEXT NOT NULL,
  message_text TEXT NOT NULL,
  audience_type TEXT NOT NULL DEFAULT 'manual',
  audience_json TEXT NULL,
  media_type TEXT NULL,
  media_url TEXT NULL,
  media_filename TEXT NULL,
  media_mime TEXT NULL,
  status TEXT NOT NULL DEFAULT 'draft',
  recipient_count INTEGER NOT NULL DEFAULT 0,
  queued_count INTEGER NOT NULL DEFAULT 0,
  sent_count INTEGER NOT NULL DEFAULT 0,
  failed_count INTEGER NOT NULL DEFAULT 0,
  created_by INTEGER NULL,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_wa_comm_tenant_status ON whatsapp_communications(tenant_id,status,created_at);

CREATE TABLE IF NOT EXISTS whatsapp_communication_recipients (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  tenant_id INTEGER NOT NULL,
  communication_id INTEGER NOT NULL,
  customer_id INTEGER NULL,
  recipient TEXT NOT NULL,
  recipient_name TEXT NULL,
  outbox_id INTEGER NULL,
  status TEXT NOT NULL DEFAULT 'pending',
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE (communication_id,recipient),
  FOREIGN KEY (communication_id) REFERENCES whatsapp_communications(id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_wa_comm_rec_tenant ON whatsapp_communication_recipients(tenant_id,communication_id,status);
