ALTER TABLE payments
  MODIFY status ENUM('created','pending','authorized','paid','duplicate_paid','failed','cancelled','refunded','partially_refunded') NOT NULL DEFAULT 'created';
