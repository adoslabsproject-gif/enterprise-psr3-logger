-- SQLite Schema for Enterprise PSR-3 Logger
-- For development and testing

CREATE TABLE IF NOT EXISTS logs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    channel TEXT NOT NULL,
    level TEXT NOT NULL,
    level_value INTEGER NOT NULL,
    message TEXT NOT NULL,
    context TEXT,  -- JSON stored as TEXT
    extra TEXT,    -- JSON stored as TEXT
    created_at TEXT NOT NULL,
    request_id TEXT
);

-- Indexes
CREATE INDEX IF NOT EXISTS idx_logs_channel ON logs(channel);
CREATE INDEX IF NOT EXISTS idx_logs_level ON logs(level_value);
CREATE INDEX IF NOT EXISTS idx_logs_created_at ON logs(created_at);
CREATE INDEX IF NOT EXISTS idx_logs_request_id ON logs(request_id);

-- Query examples:
-- SELECT * FROM logs WHERE channel = 'app' ORDER BY created_at DESC LIMIT 100;
-- SELECT * FROM logs WHERE level_value >= 400 ORDER BY created_at DESC;

-- JSON queries (SQLite 3.38+):
-- SELECT * FROM logs WHERE json_extract(context, '$.user_id') = 123;
