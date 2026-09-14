CREATE TABLE IF NOT EXISTS platform_client_releases (
  platform VARCHAR(32) PRIMARY KEY,
  version VARCHAR(40) NOT NULL DEFAULT '',
  download_url VARCHAR(1000) NOT NULL DEFAULT '',
  sha256 CHAR(64) NOT NULL DEFAULT '',
  release_notes VARCHAR(1000) NULL,
  published TINYINT(1) NOT NULL DEFAULT 0,
  config_version BIGINT UNSIGNED NOT NULL DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO platform_client_releases (platform,version,download_url,sha256,published)
SELECT 'android','','','',0
WHERE NOT EXISTS (SELECT 1 FROM platform_client_releases WHERE platform='android');

INSERT INTO platform_client_releases (platform,version,download_url,sha256,published)
SELECT 'windows','','','',0
WHERE NOT EXISTS (SELECT 1 FROM platform_client_releases WHERE platform='windows');
