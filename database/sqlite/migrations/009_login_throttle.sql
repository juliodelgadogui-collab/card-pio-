CREATE TABLE IF NOT EXISTS login_throttles (
  key_hash TEXT PRIMARY KEY,
  attempts INTEGER NOT NULL DEFAULT 0,
  window_started_at TEXT NOT NULL,
  blocked_until TEXT NULL,
  updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_login_throttles_blocked ON login_throttles(blocked_until);
CREATE INDEX IF NOT EXISTS idx_login_throttles_updated ON login_throttles(updated_at);
