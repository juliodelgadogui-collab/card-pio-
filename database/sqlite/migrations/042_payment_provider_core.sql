ALTER TABLE nfc_payment_intents ADD COLUMN provider TEXT NOT NULL DEFAULT 'pagbank';
ALTER TABLE nfc_payment_intents ADD COLUMN payment_method TEXT NOT NULL DEFAULT 'credit';
ALTER TABLE nfc_payment_intents ADD COLUMN installments_count INTEGER NOT NULL DEFAULT 1;
ALTER TABLE nfc_payment_intents ADD COLUMN client_transaction_id TEXT NULL;
ALTER TABLE nfc_payment_intents ADD COLUMN provider_transaction_id TEXT NULL;
ALTER TABLE nfc_payment_intents ADD COLUMN provider_merchant_reference TEXT NULL;
ALTER TABLE nfc_payment_intents ADD COLUMN provider_result_json TEXT NULL;
CREATE UNIQUE INDEX IF NOT EXISTS uq_nfc_provider_transaction ON nfc_payment_intents(tenant_id,provider,provider_transaction_id);
CREATE UNIQUE INDEX IF NOT EXISTS uq_nfc_client_transaction ON nfc_payment_intents(tenant_id,provider,client_transaction_id);
CREATE INDEX IF NOT EXISTS idx_nfc_provider_intents ON nfc_payment_intents(tenant_id,provider,status,expires_at);

CREATE TABLE IF NOT EXISTS payment_provider_preferences (
  tenant_id INTEGER PRIMARY KEY,
  card_present_provider TEXT NULL,
  pix_provider TEXT NULL,
  pix_fallback_provider TEXT NULL,
  updated_by INTEGER NULL,
  updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
  FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
);
