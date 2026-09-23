CREATE TABLE IF NOT EXISTS whatsapp_assistant_settings (
  tenant_id INTEGER PRIMARY KEY,
  enabled INTEGER NOT NULL DEFAULT 1,
  knowledge_enabled INTEGER NOT NULL DEFAULT 1,
  assistant_name TEXT NOT NULL DEFAULT 'Assistente EventMenu',
  tone TEXT NOT NULL DEFAULT 'friendly',
  greeting_message TEXT NULL,
  unknown_behavior TEXT NOT NULL DEFAULT 'menu',
  unknown_message TEXT NULL,
  handoff_message TEXT NULL,
  handoff_keywords TEXT NULL,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS whatsapp_assistant_knowledge (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  tenant_id INTEGER NOT NULL,
  title TEXT NOT NULL,
  answer TEXT NOT NULL,
  keywords TEXT NULL,
  enabled INTEGER NOT NULL DEFAULT 1,
  sort_order INTEGER NOT NULL DEFAULT 0,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_wa_assistant_knowledge_tenant ON whatsapp_assistant_knowledge(tenant_id,enabled,sort_order,id);
