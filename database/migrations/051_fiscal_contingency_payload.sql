ALTER TABLE fiscal_documents
  ADD COLUMN contingency_xml_encrypted LONGTEXT NULL AFTER contingency_synced_at,
  ADD COLUMN contingency_emitted_at DATETIME NULL AFTER contingency_xml_encrypted,
  ADD COLUMN contingency_device_id VARCHAR(190) NULL AFTER contingency_emitted_at;
