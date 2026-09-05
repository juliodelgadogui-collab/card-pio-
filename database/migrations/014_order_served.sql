ALTER TABLE orders
  MODIFY status ENUM('draft','pending','confirmed','preparing','ready','served','out_for_delivery','completed','cancelled') NOT NULL DEFAULT 'pending';
