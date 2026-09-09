CREATE TABLE IF NOT EXISTS whatsapp_settings (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  scope_key TEXT NOT NULL UNIQUE,
  tenant_id INTEGER NULL,
  enabled INTEGER NOT NULL DEFAULT 0,
  phone_number_id TEXT NULL,
  access_token_encrypted TEXT NULL,
  graph_version TEXT NOT NULL DEFAULT 'v25.0',
  confirmation_template TEXT NOT NULL DEFAULT 'eventmenu_order_confirmation',
  tracking_template TEXT NOT NULL DEFAULT 'eventmenu_delivery_tracking',
  language_code TEXT NOT NULL DEFAULT 'pt_BR',
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_whatsapp_settings_tenant ON whatsapp_settings(tenant_id);

CREATE TABLE IF NOT EXISTS customer_communications (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  tenant_id INTEGER NOT NULL,
  order_id INTEGER NOT NULL,
  customer_id INTEGER NULL,
  event_type TEXT NOT NULL,
  channel TEXT NOT NULL,
  status TEXT NOT NULL DEFAULT 'pending',
  recipient_hash TEXT NOT NULL,
  recipient_hint TEXT NULL,
  provider_message_id TEXT NULL,
  last_error TEXT NULL,
  sent_at TEXT NULL,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE (tenant_id,order_id,event_type,channel),
  FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
  FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
  FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE SET NULL
);
CREATE INDEX IF NOT EXISTS idx_customer_communication_status ON customer_communications(status,created_at);
