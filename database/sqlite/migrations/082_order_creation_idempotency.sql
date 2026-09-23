ALTER TABLE orders ADD COLUMN creation_idempotency_key TEXT NULL;
ALTER TABLE orders ADD COLUMN creation_request_hash TEXT NULL;
CREATE UNIQUE INDEX IF NOT EXISTS uq_orders_creation_idempotency ON orders (tenant_id, creation_idempotency_key) WHERE creation_idempotency_key IS NOT NULL;
