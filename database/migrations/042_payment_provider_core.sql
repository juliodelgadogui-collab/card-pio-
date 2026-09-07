ALTER TABLE nfc_payment_intents ADD COLUMN provider VARCHAR(40) NOT NULL DEFAULT 'pagbank' AFTER amount_cents;
ALTER TABLE nfc_payment_intents ADD COLUMN payment_method VARCHAR(20) NOT NULL DEFAULT 'credit' AFTER provider;
ALTER TABLE nfc_payment_intents ADD COLUMN installments_count INT NOT NULL DEFAULT 1 AFTER payment_method;
ALTER TABLE nfc_payment_intents ADD COLUMN client_transaction_id VARCHAR(190) NULL AFTER installments_count;
ALTER TABLE nfc_payment_intents ADD COLUMN provider_transaction_id VARCHAR(190) NULL AFTER provider_transaction_code;
ALTER TABLE nfc_payment_intents ADD COLUMN provider_merchant_reference VARCHAR(190) NULL AFTER provider_transaction_id;
ALTER TABLE nfc_payment_intents ADD COLUMN provider_result_json LONGTEXT NULL AFTER provider_merchant_reference;
CREATE UNIQUE INDEX uq_nfc_provider_transaction ON nfc_payment_intents(tenant_id,provider,provider_transaction_id);
CREATE UNIQUE INDEX uq_nfc_client_transaction ON nfc_payment_intents(tenant_id,provider,client_transaction_id);
CREATE INDEX idx_nfc_provider_intents ON nfc_payment_intents(tenant_id,provider,status,expires_at);

CREATE TABLE payment_provider_preferences (
  tenant_id BIGINT UNSIGNED PRIMARY KEY,
  card_present_provider VARCHAR(40) NULL,
  pix_provider VARCHAR(40) NULL,
  pix_fallback_provider VARCHAR(40) NULL,
  updated_by BIGINT UNSIGNED NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_payment_pref_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
  CONSTRAINT fk_payment_pref_user FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
