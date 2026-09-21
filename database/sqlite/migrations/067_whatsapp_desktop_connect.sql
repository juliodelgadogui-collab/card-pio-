ALTER TABLE whatsapp_outbox ADD COLUMN claim_token TEXT NULL;
ALTER TABLE whatsapp_outbox ADD COLUMN claimed_by_device_hash TEXT NULL;
ALTER TABLE whatsapp_outbox ADD COLUMN claim_expires_at TEXT NULL;
CREATE INDEX IF NOT EXISTS idx_whatsapp_outbox_desktop_claim ON whatsapp_outbox (tenant_id,status,available_at,claim_expires_at,id);

CREATE TABLE IF NOT EXISTS whatsapp_desktop_agents (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  tenant_id INTEGER NOT NULL,
  device_hash TEXT NOT NULL,
  device_label TEXT NULL,
  status TEXT NOT NULL DEFAULT 'disconnected',
  phone_number TEXT NULL,
  engine TEXT NOT NULL DEFAULT 'baileys',
  last_seen_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_error TEXT NULL,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
  UNIQUE (tenant_id,device_hash)
);
CREATE INDEX IF NOT EXISTS idx_whatsapp_desktop_agent_seen ON whatsapp_desktop_agents (tenant_id,last_seen_at,status);
