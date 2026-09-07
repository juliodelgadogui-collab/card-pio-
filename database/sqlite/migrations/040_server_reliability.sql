CREATE TABLE IF NOT EXISTS background_jobs (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  tenant_id INTEGER NULL,
  type TEXT NOT NULL,
  payload TEXT NULL,
  dedupe_key TEXT NULL UNIQUE,
  status TEXT NOT NULL DEFAULT 'pending',
  attempts INTEGER NOT NULL DEFAULT 0,
  max_attempts INTEGER NOT NULL DEFAULT 5,
  run_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  locked_at TEXT NULL,
  locked_by TEXT NULL,
  last_error TEXT NULL,
  completed_at TEXT NULL,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_background_jobs_ready ON background_jobs(status,run_at,id);
CREATE INDEX IF NOT EXISTS idx_background_jobs_tenant ON background_jobs(tenant_id,status,id);

CREATE TABLE IF NOT EXISTS push_devices (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  tenant_id INTEGER NOT NULL,
  user_id INTEGER NOT NULL,
  device_id TEXT NOT NULL,
  platform TEXT NOT NULL DEFAULT 'android',
  token_hash TEXT NOT NULL UNIQUE,
  push_token TEXT NOT NULL,
  active INTEGER NOT NULL DEFAULT 1,
  last_seen_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE(tenant_id,user_id,device_id)
);
CREATE INDEX IF NOT EXISTS idx_push_user_active ON push_devices(tenant_id,user_id,active);

CREATE TABLE IF NOT EXISTS system_runtime_status (
  status_key TEXT PRIMARY KEY,
  state TEXT NOT NULL DEFAULT 'ok',
  message TEXT NULL,
  metadata TEXT NULL,
  checked_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS api_rate_limits (
  key_hash TEXT PRIMARY KEY,
  bucket TEXT NOT NULL,
  hits INTEGER NOT NULL DEFAULT 0,
  window_started_at TEXT NOT NULL,
  expires_at TEXT NOT NULL,
  updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_api_rate_limits_expiry ON api_rate_limits(expires_at);
