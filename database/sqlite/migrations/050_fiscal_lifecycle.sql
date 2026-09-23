ALTER TABLE fiscal_documents ADD COLUMN contingency_mode TEXT NULL;
ALTER TABLE fiscal_documents ADD COLUMN contingency_reason TEXT NULL;
ALTER TABLE fiscal_documents ADD COLUMN contingency_started_at TEXT NULL;
ALTER TABLE fiscal_documents ADD COLUMN contingency_synced_at TEXT NULL;

CREATE TABLE IF NOT EXISTS fiscal_events (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  tenant_id INTEGER NOT NULL,
  fiscal_document_id INTEGER NOT NULL,
  event_type TEXT NOT NULL,
  sequence_no INTEGER NOT NULL DEFAULT 1,
  status TEXT NOT NULL DEFAULT 'queued',
  reason TEXT NOT NULL,
  protocol TEXT NULL,
  rejection_code TEXT NULL,
  rejection_message TEXT NULL,
  request_xml_encrypted TEXT NULL,
  response_xml_encrypted TEXT NULL,
  response_encrypted TEXT NULL,
  attempts INTEGER NOT NULL DEFAULT 0,
  started_at TEXT NULL,
  authorized_at TEXT NULL,
  last_attempt_at TEXT NULL,
  idempotency_key TEXT NOT NULL,
  created_by INTEGER NULL,
  created_at TEXT DEFAULT CURRENT_TIMESTAMP,
  updated_at TEXT DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
  FOREIGN KEY (fiscal_document_id) REFERENCES fiscal_documents(id) ON DELETE CASCADE,
  UNIQUE (tenant_id,idempotency_key),
  UNIQUE (tenant_id,fiscal_document_id,event_type,sequence_no)
);
CREATE INDEX IF NOT EXISTS idx_fiscal_event_queue ON fiscal_events(tenant_id,status,event_type,created_at);

CREATE TABLE IF NOT EXISTS fiscal_inutilizations (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  tenant_id INTEGER NOT NULL,
  unit_id INTEGER NOT NULL,
  fiscal_profile_id INTEGER NOT NULL,
  model TEXT NOT NULL,
  environment TEXT NOT NULL,
  fiscal_year INTEGER NOT NULL,
  series INTEGER NOT NULL,
  number_start INTEGER NOT NULL,
  number_end INTEGER NOT NULL,
  justification TEXT NOT NULL,
  status TEXT NOT NULL DEFAULT 'queued',
  protocol TEXT NULL,
  rejection_code TEXT NULL,
  rejection_message TEXT NULL,
  request_xml_encrypted TEXT NULL,
  response_xml_encrypted TEXT NULL,
  response_encrypted TEXT NULL,
  attempts INTEGER NOT NULL DEFAULT 0,
  started_at TEXT NULL,
  authorized_at TEXT NULL,
  last_attempt_at TEXT NULL,
  idempotency_key TEXT NOT NULL,
  created_by INTEGER NULL,
  created_at TEXT DEFAULT CURRENT_TIMESTAMP,
  updated_at TEXT DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
  FOREIGN KEY (unit_id) REFERENCES operating_units(id) ON DELETE CASCADE,
  FOREIGN KEY (fiscal_profile_id) REFERENCES fiscal_profiles(id) ON DELETE CASCADE,
  UNIQUE (tenant_id,idempotency_key)
);
CREATE INDEX IF NOT EXISTS idx_fiscal_inut_queue ON fiscal_inutilizations(tenant_id,status,created_at);
CREATE INDEX IF NOT EXISTS idx_fiscal_inut_range ON fiscal_inutilizations(tenant_id,model,series,fiscal_year,number_start,number_end);
