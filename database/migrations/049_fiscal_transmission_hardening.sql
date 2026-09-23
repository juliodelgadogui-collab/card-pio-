ALTER TABLE fiscal_documents
  ADD COLUMN transmission_attempts INT UNSIGNED NOT NULL DEFAULT 0 AFTER snapshot_hash,
  ADD COLUMN transmission_started_at DATETIME NULL AFTER transmission_attempts,
  ADD COLUMN last_transmission_at DATETIME NULL AFTER transmission_started_at,
  ADD COLUMN response_encrypted LONGTEXT NULL AFTER last_transmission_at,
  ADD UNIQUE KEY uq_fiscal_access_key (access_key);
