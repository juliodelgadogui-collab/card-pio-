CREATE TABLE IF NOT EXISTS whatsapp_commerce_conversion_settings (
  tenant_id INTEGER NOT NULL PRIMARY KEY,
  repeat_last_order_enabled INTEGER NOT NULL DEFAULT 1,
  upsell_enabled INTEGER NOT NULL DEFAULT 1,
  upsell_max_suggestions INTEGER NOT NULL DEFAULT 3,
  abandoned_cart_enabled INTEGER NOT NULL DEFAULT 1,
  abandoned_delay_minutes INTEGER NOT NULL DEFAULT 60,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE
);
