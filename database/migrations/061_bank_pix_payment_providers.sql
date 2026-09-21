ALTER TABLE payment_gateways
  MODIFY provider ENUM('stripe','pagbank','mercadopago','manual','efi','inter') NOT NULL;

ALTER TABLE payments
  MODIFY provider ENUM('stripe','pagbank','mercadopago','manual','tef','efi','inter') NOT NULL;

ALTER TABLE refunds
  MODIFY provider ENUM('stripe','pagbank','mercadopago','manual','efi','inter') NOT NULL;
