ALTER TABLE payments
  MODIFY provider ENUM('stripe','pagbank','mercadopago','manual','tef') NOT NULL;
