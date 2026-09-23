ALTER TABLE ticket_types ADD COLUMN price DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER capacity_total;
ALTER TABLE ticket_types ADD COLUMN sale_start_at DATETIME NULL AFTER price;
ALTER TABLE ticket_types ADD COLUMN sale_end_at DATETIME NULL AFTER sale_start_at;
ALTER TABLE ticket_types ADD COLUMN sales_enabled TINYINT(1) NOT NULL DEFAULT 1 AFTER sale_end_at;
