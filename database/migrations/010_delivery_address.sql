ALTER TABLE orders
  ADD COLUMN delivery_postal_code VARCHAR(12) NULL AFTER delivery_address,
  ADD COLUMN delivery_neighborhood VARCHAR(120) NULL AFTER delivery_postal_code,
  ADD COLUMN delivery_city VARCHAR(120) NULL AFTER delivery_neighborhood;
