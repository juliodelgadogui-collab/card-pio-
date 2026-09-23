CREATE TABLE IF NOT EXISTS fiscal_profiles (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  tenant_id INTEGER NOT NULL,
  unit_id INTEGER NOT NULL,
  enabled INTEGER NOT NULL DEFAULT 0,
  environment TEXT NOT NULL DEFAULT 'homologation' CHECK (environment IN ('homologation','production')),
  default_document TEXT NOT NULL DEFAULT 'nfce' CHECK (default_document IN ('nfce','nfe')),
  legal_name TEXT NULL,
  trade_name TEXT NULL,
  cnpj TEXT NOT NULL DEFAULT '',
  state_registration TEXT NOT NULL DEFAULT '',
  state_code TEXT NOT NULL DEFAULT '',
  city_code TEXT NULL,
  tax_regime TEXT NULL,
  nfce_series INTEGER NOT NULL DEFAULT 1,
  nfce_next_number INTEGER NOT NULL DEFAULT 1,
  nfe_series INTEGER NOT NULL DEFAULT 1,
  nfe_next_number INTEGER NOT NULL DEFAULT 1,
  csc_id TEXT NULL,
  csc_token_encrypted TEXT NULL,
  certificate_mode TEXT NOT NULL DEFAULT 'server' CHECK (certificate_mode IN ('server','desktop','hybrid','a3_local')),
  contingency_enabled INTEGER NOT NULL DEFAULT 0,
  created_at TEXT DEFAULT CURRENT_TIMESTAMP,
  updated_at TEXT DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
  FOREIGN KEY (unit_id) REFERENCES operating_units(id) ON DELETE CASCADE,
  UNIQUE (tenant_id, unit_id)
);
CREATE INDEX IF NOT EXISTS idx_fiscal_profile_enabled ON fiscal_profiles(tenant_id, enabled);

CREATE TABLE IF NOT EXISTS fiscal_certificates (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  tenant_id INTEGER NOT NULL,
  fiscal_profile_id INTEGER NOT NULL,
  certificate_type TEXT NOT NULL DEFAULT 'a1' CHECK (certificate_type IN ('a1','a3')),
  storage_scope TEXT NOT NULL DEFAULT 'server' CHECK (storage_scope IN ('server','hybrid','local_reference')),
  pfx_encrypted TEXT NULL,
  password_encrypted TEXT NULL,
  subject_name TEXT NULL,
  issuer_name TEXT NULL,
  serial_number TEXT NULL,
  thumbprint TEXT NULL,
  valid_from TEXT NULL,
  valid_until TEXT NULL,
  status TEXT NOT NULL DEFAULT 'active' CHECK (status IN ('active','expired','revoked','disabled')),
  created_at TEXT DEFAULT CURRENT_TIMESTAMP,
  updated_at TEXT DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
  FOREIGN KEY (fiscal_profile_id) REFERENCES fiscal_profiles(id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_fiscal_cert_profile ON fiscal_certificates(fiscal_profile_id, status, valid_until);

CREATE TABLE IF NOT EXISTS fiscal_documents (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  tenant_id INTEGER NOT NULL,
  unit_id INTEGER NOT NULL,
  fiscal_profile_id INTEGER NOT NULL,
  order_id INTEGER NULL,
  model TEXT NOT NULL CHECK (model IN ('55','65')),
  environment TEXT NOT NULL CHECK (environment IN ('homologation','production')),
  series INTEGER NOT NULL,
  document_number INTEGER NOT NULL,
  status TEXT NOT NULL DEFAULT 'queued' CHECK (status IN ('queued','processing','authorized','rejected','cancelled','contingency','error')),
  access_key TEXT NULL,
  protocol TEXT NULL,
  rejection_code TEXT NULL,
  rejection_message TEXT NULL,
  xml_encrypted TEXT NULL,
  cancellation_xml_encrypted TEXT NULL,
  idempotency_key TEXT NOT NULL,
  issued_at TEXT NULL,
  authorized_at TEXT NULL,
  cancelled_at TEXT NULL,
  created_at TEXT DEFAULT CURRENT_TIMESTAMP,
  updated_at TEXT DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
  FOREIGN KEY (unit_id) REFERENCES operating_units(id) ON DELETE RESTRICT,
  FOREIGN KEY (fiscal_profile_id) REFERENCES fiscal_profiles(id) ON DELETE RESTRICT,
  FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE SET NULL,
  UNIQUE (tenant_id, idempotency_key),
  UNIQUE (tenant_id, fiscal_profile_id, model, series, document_number)
);
CREATE INDEX IF NOT EXISTS idx_fiscal_doc_order ON fiscal_documents(tenant_id, order_id);
CREATE INDEX IF NOT EXISTS idx_fiscal_doc_status ON fiscal_documents(tenant_id, status, created_at);

CREATE TABLE IF NOT EXISTS payment_terminal_configs (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  tenant_id INTEGER NOT NULL,
  unit_id INTEGER NOT NULL,
  provider TEXT NOT NULL CHECK (provider IN ('pagbank_tef','stone_tef','sitef','generic_tef')),
  enabled INTEGER NOT NULL DEFAULT 0,
  integration_mode TEXT NOT NULL DEFAULT 'local_service' CHECK (integration_mode IN ('dll','local_service','tcp','serial')),
  terminal_label TEXT NULL,
  pinpad_identifier TEXT NULL,
  config_encrypted TEXT NULL,
  auto_capture INTEGER NOT NULL DEFAULT 1,
  created_at TEXT DEFAULT CURRENT_TIMESTAMP,
  updated_at TEXT DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
  FOREIGN KEY (unit_id) REFERENCES operating_units(id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_terminal_unit ON payment_terminal_configs(tenant_id, unit_id, enabled);

CREATE TABLE IF NOT EXISTS desktop_hardware_bindings (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  tenant_id INTEGER NOT NULL,
  unit_id INTEGER NOT NULL,
  device_hash TEXT NOT NULL,
  device_label TEXT NULL,
  hardware_json TEXT NULL,
  last_seen_at TEXT NULL,
  revoked_at TEXT NULL,
  created_at TEXT DEFAULT CURRENT_TIMESTAMP,
  updated_at TEXT DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
  FOREIGN KEY (unit_id) REFERENCES operating_units(id) ON DELETE CASCADE,
  UNIQUE (tenant_id, device_hash)
);
CREATE INDEX IF NOT EXISTS idx_hw_binding_unit ON desktop_hardware_bindings(tenant_id, unit_id, revoked_at);
