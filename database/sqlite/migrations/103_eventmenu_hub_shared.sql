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

CREATE TABLE IF NOT EXISTS hub_pairing_codes (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  tenant_id INTEGER NOT NULL,
  unit_id INTEGER NOT NULL,
  desktop_binding_id INTEGER NOT NULL,
  code_hash TEXT NOT NULL UNIQUE,
  status TEXT NOT NULL DEFAULT 'pending' CHECK (status IN ('pending','claimed','expired','revoked')),
  claimed_by_user_id INTEGER NULL,
  claimed_device_hash TEXT NULL,
  expires_at TEXT NOT NULL,
  claimed_at TEXT NULL,
  created_at TEXT DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
  FOREIGN KEY (unit_id) REFERENCES operating_units(id) ON DELETE CASCADE,
  FOREIGN KEY (desktop_binding_id) REFERENCES desktop_hardware_bindings(id) ON DELETE CASCADE,
  FOREIGN KEY (claimed_by_user_id) REFERENCES users(id) ON DELETE SET NULL
);
CREATE INDEX IF NOT EXISTS idx_hub_pair_pending ON hub_pairing_codes(tenant_id, desktop_binding_id, status, expires_at);

CREATE TABLE IF NOT EXISTS hub_device_links (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  tenant_id INTEGER NOT NULL,
  unit_id INTEGER NOT NULL,
  desktop_binding_id INTEGER NOT NULL,
  mobile_user_id INTEGER NOT NULL,
  mobile_device_hash TEXT NOT NULL,
  label TEXT NULL,
  status TEXT NOT NULL DEFAULT 'active' CHECK (status IN ('active','revoked')),
  last_seen_at TEXT NULL,
  revoked_at TEXT NULL,
  created_at TEXT DEFAULT CURRENT_TIMESTAMP,
  updated_at TEXT DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
  FOREIGN KEY (unit_id) REFERENCES operating_units(id) ON DELETE CASCADE,
  FOREIGN KEY (desktop_binding_id) REFERENCES desktop_hardware_bindings(id) ON DELETE CASCADE,
  FOREIGN KEY (mobile_user_id) REFERENCES users(id) ON DELETE CASCADE,
  UNIQUE (tenant_id, desktop_binding_id, mobile_device_hash)
);
CREATE INDEX IF NOT EXISTS idx_hub_link_mobile ON hub_device_links(tenant_id, mobile_user_id, status);
CREATE INDEX IF NOT EXISTS idx_hub_link_unit ON hub_device_links(tenant_id, unit_id, status);

CREATE TABLE IF NOT EXISTS hub_commands (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  tenant_id INTEGER NOT NULL,
  unit_id INTEGER NOT NULL,
  target_binding_id INTEGER NOT NULL,
  requested_by_user_id INTEGER NOT NULL,
  source_device_hash TEXT NULL,
  command_type TEXT NOT NULL CHECK (command_type IN ('print_order','print_receipt','open_drawer','tef_charge','customer_display','kitchen_alert','play_alert','print_label')),
  payload_json TEXT NULL,
  idempotency_key TEXT NOT NULL,
  status TEXT NOT NULL DEFAULT 'queued' CHECK (status IN ('queued','claimed','completed','failed','cancelled','expired')),
  result_json TEXT NULL,
  error_message TEXT NULL,
  claimed_at TEXT NULL,
  completed_at TEXT NULL,
  expires_at TEXT NOT NULL,
  created_at TEXT DEFAULT CURRENT_TIMESTAMP,
  updated_at TEXT DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
  FOREIGN KEY (unit_id) REFERENCES operating_units(id) ON DELETE CASCADE,
  FOREIGN KEY (target_binding_id) REFERENCES desktop_hardware_bindings(id) ON DELETE CASCADE,
  FOREIGN KEY (requested_by_user_id) REFERENCES users(id) ON DELETE RESTRICT,
  UNIQUE (tenant_id, idempotency_key)
);
CREATE INDEX IF NOT EXISTS idx_hub_cmd_poll ON hub_commands(tenant_id, target_binding_id, status, expires_at, id);
CREATE INDEX IF NOT EXISTS idx_hub_cmd_source ON hub_commands(tenant_id, requested_by_user_id, created_at);
