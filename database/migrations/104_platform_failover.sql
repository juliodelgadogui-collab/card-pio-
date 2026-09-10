CREATE TABLE IF NOT EXISTS platform_failover_settings (
  id SMALLINT UNSIGNED PRIMARY KEY,
  enabled TINYINT(1) NOT NULL DEFAULT 0,
  primary_url VARCHAR(500) NOT NULL,
  contingency_url VARCHAR(500) NULL,
  mode ENUM('shared_db','read_only') NOT NULL DEFAULT 'read_only',
  cluster_id VARCHAR(64) NOT NULL,
  cluster_secret_encrypted LONGTEXT NOT NULL,
  config_version BIGINT UNSIGNED NOT NULL DEFAULT 1,
  last_health_status ENUM('unknown','healthy','degraded','offline','misconfigured') NOT NULL DEFAULT 'unknown',
  last_health_message VARCHAR(500) NULL,
  last_health_db_driver VARCHAR(32) NULL,
  last_health_writable TINYINT(1) NOT NULL DEFAULT 0,
  last_health_checked_at DATETIME NULL,
  verified_at DATETIME NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
