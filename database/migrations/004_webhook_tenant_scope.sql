ALTER TABLE webhook_events ADD COLUMN tenant_id BIGINT UNSIGNED NULL AFTER id;
ALTER TABLE webhook_events ADD CONSTRAINT fk_webhook_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE;
CREATE INDEX idx_webhook_tenant_created ON webhook_events(tenant_id,created_at);
