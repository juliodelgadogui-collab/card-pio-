CREATE TABLE IF NOT EXISTS background_jobs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id BIGINT UNSIGNED NULL,
  type VARCHAR(80) NOT NULL,
  payload JSON NULL,
  dedupe_key VARCHAR(190) NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'pending',
  attempts INT UNSIGNED NOT NULL DEFAULT 0,
  max_attempts INT UNSIGNED NOT NULL DEFAULT 5,
  run_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  locked_at TIMESTAMP NULL,
  locked_by VARCHAR(100) NULL,
  last_error TEXT NULL,
  completed_at TIMESTAMP NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_background_jobs_dedupe (dedupe_key),
  INDEX idx_background_jobs_ready (status,run_at,id),
  INDEX idx_background_jobs_tenant (tenant_id,status,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS push_devices (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  device_id VARCHAR(190) NOT NULL,
  platform VARCHAR(20) NOT NULL DEFAULT 'android',
  token_hash CHAR(64) NOT NULL,
  push_token TEXT NOT NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  last_seen_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_push_device (tenant_id,user_id,device_id),
  UNIQUE KEY uniq_push_token_hash (token_hash),
  INDEX idx_push_user_active (tenant_id,user_id,active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS system_runtime_status (
  status_key VARCHAR(100) PRIMARY KEY,
  state VARCHAR(20) NOT NULL DEFAULT 'ok',
  message VARCHAR(500) NULL,
  metadata JSON NULL,
  checked_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS api_rate_limits (
  key_hash CHAR(64) PRIMARY KEY,
  bucket VARCHAR(60) NOT NULL,
  hits INT UNSIGNED NOT NULL DEFAULT 0,
  window_started_at TIMESTAMP NOT NULL,
  expires_at TIMESTAMP NOT NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_api_rate_limits_expiry (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
