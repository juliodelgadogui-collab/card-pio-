CREATE TABLE IF NOT EXISTS platform_client_releases (
  platform TEXT PRIMARY KEY,
  version TEXT NOT NULL DEFAULT '',
  download_url TEXT NOT NULL DEFAULT '',
  sha256 TEXT NOT NULL DEFAULT '',
  release_notes TEXT NULL,
  published INTEGER NOT NULL DEFAULT 0,
  config_version INTEGER NOT NULL DEFAULT 1,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

INSERT INTO platform_client_releases (platform,version,download_url,sha256,published)
SELECT 'android','','','',0
WHERE NOT EXISTS (SELECT 1 FROM platform_client_releases WHERE platform='android');

INSERT INTO platform_client_releases (platform,version,download_url,sha256,published)
SELECT 'windows','','','',0
WHERE NOT EXISTS (SELECT 1 FROM platform_client_releases WHERE platform='windows');
