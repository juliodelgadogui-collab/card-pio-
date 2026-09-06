ALTER TABLE purchase_order_items
  ADD COLUMN purchase_unit_snapshot VARCHAR(40) NULL,
  ADD COLUMN stock_unit_snapshot VARCHAR(40) NULL,
  ADD COLUMN purchase_factor_snapshot DECIMAL(18,6) NOT NULL DEFAULT 1,
  ADD COLUMN stock_quantity DECIMAL(18,6) NOT NULL DEFAULT 0;

CREATE INDEX idx_purchase_items_product ON purchase_order_items(product_id,purchase_order_id);

ALTER TABLE inventory_count_items
  ADD COLUMN adjustment_movement_id BIGINT UNSIGNED NULL,
  ADD CONSTRAINT fk_inventory_count_adjustment_movement FOREIGN KEY (adjustment_movement_id) REFERENCES stock_movements(id) ON DELETE SET NULL;

ALTER TABLE inventory_counts ADD COLUMN closed_at DATETIME NULL;
