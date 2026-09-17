CREATE TABLE IF NOT EXISTS platform_policy_signing (
  id SMALLINT UNSIGNED PRIMARY KEY,
  key_id VARCHAR(64) NOT NULL,
  public_key_pem LONGTEXT NOT NULL,
  private_key_encrypted LONGTEXT NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  rotated_at DATETIME NULL,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS platform_client_policies (
  platform VARCHAR(32) PRIMARY KEY,
  enabled TINYINT(1) NOT NULL DEFAULT 1,
  min_version VARCHAR(40) NOT NULL DEFAULT '',
  recommended_version VARCHAR(40) NOT NULL DEFAULT '',
  maintenance TINYINT(1) NOT NULL DEFAULT 0,
  maintenance_message VARCHAR(500) NULL,
  features_json LONGTEXT NULL,
  allowed_signing_fingerprints_json LONGTEXT NULL,
  config_version BIGINT UNSIGNED NOT NULL DEFAULT 1,
  ttl_seconds INT UNSIGNED NOT NULL DEFAULT 86400,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO platform_client_policies (platform,enabled,min_version,recommended_version,maintenance,features_json,allowed_signing_fingerprints_json,ttl_seconds)
SELECT 'android',1,'0.2.0','','0','{}','[]',86400
WHERE NOT EXISTS (SELECT 1 FROM platform_client_policies WHERE platform='android');

INSERT INTO platform_client_policies (platform,enabled,min_version,recommended_version,maintenance,features_json,allowed_signing_fingerprints_json,ttl_seconds)
SELECT 'windows',1,'','','0','{}','[]',86400
WHERE NOT EXISTS (SELECT 1 FROM platform_client_policies WHERE platform='windows');
