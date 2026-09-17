CREATE TABLE IF NOT EXISTS platform_policy_signing (
  id INTEGER PRIMARY KEY,
  key_id TEXT NOT NULL,
  public_key_pem TEXT NOT NULL,
  private_key_encrypted TEXT NOT NULL,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  rotated_at TEXT NULL,
  updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS platform_client_policies (
  platform TEXT PRIMARY KEY,
  enabled INTEGER NOT NULL DEFAULT 1,
  min_version TEXT NOT NULL DEFAULT '',
  recommended_version TEXT NOT NULL DEFAULT '',
  maintenance INTEGER NOT NULL DEFAULT 0,
  maintenance_message TEXT NULL,
  features_json TEXT NULL,
  allowed_signing_fingerprints_json TEXT NULL,
  config_version INTEGER NOT NULL DEFAULT 1,
  ttl_seconds INTEGER NOT NULL DEFAULT 86400,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

INSERT OR IGNORE INTO platform_client_policies (platform,enabled,min_version,recommended_version,maintenance,features_json,allowed_signing_fingerprints_json,ttl_seconds)
VALUES ('android',1,'0.2.0','',0,'{}','[]',86400);

INSERT OR IGNORE INTO platform_client_policies (platform,enabled,min_version,recommended_version,maintenance,features_json,allowed_signing_fingerprints_json,ttl_seconds)
VALUES ('windows',1,'','',0,'{}','[]',86400);
