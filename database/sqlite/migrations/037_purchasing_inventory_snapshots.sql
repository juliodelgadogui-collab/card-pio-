PRAGMA foreign_keys = ON;

ALTER TABLE purchase_order_items ADD COLUMN purchase_unit_snapshot TEXT NULL;
ALTER TABLE purchase_order_items ADD COLUMN stock_unit_snapshot TEXT NULL;
ALTER TABLE purchase_order_items ADD COLUMN purchase_factor_snapshot REAL NOT NULL DEFAULT 1;
ALTER TABLE purchase_order_items ADD COLUMN stock_quantity REAL NOT NULL DEFAULT 0;

CREATE INDEX idx_purchase_items_product ON purchase_order_items(product_id,purchase_order_id);

-- Complementos para rastreabilidade da contagem física.
ALTER TABLE inventory_count_items ADD COLUMN adjustment_movement_id INTEGER NULL REFERENCES stock_movements(id) ON DELETE SET NULL;
ALTER TABLE inventory_counts ADD COLUMN closed_at TEXT NULL;
