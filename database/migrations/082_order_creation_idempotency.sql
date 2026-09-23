ALTER TABLE orders ADD COLUMN creation_idempotency_key VARCHAR(190) NULL AFTER public_token;
ALTER TABLE orders ADD COLUMN creation_request_hash CHAR(64) NULL AFTER creation_idempotency_key;
CREATE UNIQUE INDEX uq_orders_creation_idempotency ON orders (tenant_id, creation_idempotency_key);
