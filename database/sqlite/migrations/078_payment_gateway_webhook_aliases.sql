CREATE TABLE IF NOT EXISTS payment_gateway_webhook_aliases (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  tenant_id INTEGER NOT NULL,
  provider TEXT NOT NULL,
  alias_slug TEXT NOT NULL,
  active INTEGER NOT NULL DEFAULT 1,
  created_at TEXT DEFAULT CURRENT_TIMESTAMP,
  updated_at TEXT DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
  UNIQUE (provider, alias_slug)
);
CREATE INDEX IF NOT EXISTS idx_gateway_webhook_alias_tenant ON payment_gateway_webhook_aliases (tenant_id, provider, active);

INSERT OR IGNORE INTO payment_gateway_webhook_aliases (tenant_id,provider,alias_slug,active)
SELECT g.tenant_id,g.provider,t.slug,1
FROM payment_gateways g
JOIN tenants t ON t.id=g.tenant_id
WHERE TRIM(t.slug)<>'';
