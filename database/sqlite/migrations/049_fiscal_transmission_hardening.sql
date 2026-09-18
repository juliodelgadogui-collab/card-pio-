ALTER TABLE fiscal_documents ADD COLUMN transmission_attempts INTEGER NOT NULL DEFAULT 0;
ALTER TABLE fiscal_documents ADD COLUMN transmission_started_at TEXT NULL;
ALTER TABLE fiscal_documents ADD COLUMN last_transmission_at TEXT NULL;
ALTER TABLE fiscal_documents ADD COLUMN response_encrypted TEXT NULL;
CREATE UNIQUE INDEX IF NOT EXISTS uq_fiscal_access_key ON fiscal_documents(access_key);
