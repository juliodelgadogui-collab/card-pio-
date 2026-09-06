ALTER TABLE order_item_modifiers ADD COLUMN inventory_product_id INTEGER NULL REFERENCES products(id) ON DELETE SET NULL;
ALTER TABLE order_item_modifiers ADD COLUMN inventory_quantity REAL NOT NULL DEFAULT 0;
ALTER TABLE order_item_modifiers ADD COLUMN cost_cents INTEGER NOT NULL DEFAULT 0;
CREATE INDEX IF NOT EXISTS idx_order_item_mod_inventory ON order_item_modifiers(tenant_id,order_id,inventory_product_id);
