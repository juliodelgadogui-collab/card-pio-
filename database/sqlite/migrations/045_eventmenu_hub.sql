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

CREATE TABLE IF NOT EXISTS hub_approval_requests (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  tenant_id INTEGER NOT NULL,
  unit_id INTEGER NOT NULL,
  requested_by_user_id INTEGER NOT NULL,
  approval_type TEXT NOT NULL CHECK (approval_type IN ('discount','cancellation','refund','stock_adjustment','drawer_open','fiscal_cancel')),
  entity_type TEXT NOT NULL,
  entity_id INTEGER NOT NULL,
  payload_json TEXT NULL,
  status TEXT NOT NULL DEFAULT 'pending' CHECK (status IN ('pending','approved','rejected','expired','cancelled')),
  decided_by_user_id INTEGER NULL,
  decision_note TEXT NULL,
  expires_at TEXT NOT NULL,
  decided_at TEXT NULL,
  created_at TEXT DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
  FOREIGN KEY (unit_id) REFERENCES operating_units(id) ON DELETE CASCADE,
  FOREIGN KEY (requested_by_user_id) REFERENCES users(id) ON DELETE RESTRICT,
  FOREIGN KEY (decided_by_user_id) REFERENCES users(id) ON DELETE SET NULL
);
CREATE INDEX IF NOT EXISTS idx_hub_approval_pending ON hub_approval_requests(tenant_id, status, expires_at);
CREATE INDEX IF NOT EXISTS idx_hub_approval_entity ON hub_approval_requests(tenant_id, entity_type, entity_id);
