ALTER TABLE order_item_modifiers ADD COLUMN inventory_product_id BIGINT UNSIGNED NULL;
ALTER TABLE order_item_modifiers ADD COLUMN inventory_quantity DECIMAL(12,3) NOT NULL DEFAULT 0;
ALTER TABLE order_item_modifiers ADD COLUMN cost_cents INT UNSIGNED NOT NULL DEFAULT 0;
ALTER TABLE order_item_modifiers ADD CONSTRAINT fk_order_mod_inventory_product FOREIGN KEY (inventory_product_id) REFERENCES products(id) ON DELETE SET NULL;
CREATE INDEX idx_order_item_mod_inventory ON order_item_modifiers(tenant_id,order_id,inventory_product_id);
