CREATE TABLE IF NOT EXISTS platform_failover_settings (
  id INTEGER PRIMARY KEY,
  enabled INTEGER NOT NULL DEFAULT 0,
  primary_url TEXT NOT NULL,
  contingency_url TEXT NULL,
  mode TEXT NOT NULL DEFAULT 'read_only' CHECK (mode IN ('shared_db','read_only')),
  cluster_id TEXT NOT NULL,
  cluster_secret_encrypted TEXT NOT NULL,
  config_version INTEGER NOT NULL DEFAULT 1,
  last_health_status TEXT NOT NULL DEFAULT 'unknown' CHECK (last_health_status IN ('unknown','healthy','degraded','offline','misconfigured')),
  last_health_message TEXT NULL,
  last_health_db_driver TEXT NULL,
  last_health_writable INTEGER NOT NULL DEFAULT 0,
  last_health_checked_at TEXT NULL,
  verified_at TEXT NULL,
  created_at TEXT DEFAULT CURRENT_TIMESTAMP,
  updated_at TEXT DEFAULT CURRENT_TIMESTAMP
);
