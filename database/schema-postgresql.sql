-- PostgreSQL Schema for Enterprise PSR-3 Logger
-- Run this to create the logs table in your PostgreSQL database

CREATE TABLE IF NOT EXISTS logs (
    id BIGSERIAL PRIMARY KEY,
    channel VARCHAR(255) NOT NULL,
    level VARCHAR(20) NOT NULL,
    level_value SMALLINT NOT NULL,
    message TEXT NOT NULL,
    context JSONB,
    extra JSONB,
    created_at TIMESTAMP(6) NOT NULL,
    request_id VARCHAR(36)
);

-- Indexes for common queries
CREATE INDEX IF NOT EXISTS idx_logs_channel ON logs(channel);
CREATE INDEX IF NOT EXISTS idx_logs_level ON logs(level_value);
CREATE INDEX IF NOT EXISTS idx_logs_created_at ON logs(created_at);
CREATE INDEX IF NOT EXISTS idx_logs_request_id ON logs(request_id);

-- Composite index for channel + time range queries
CREATE INDEX IF NOT EXISTS idx_logs_channel_time ON logs(channel, created_at);

-- GIN index for JSONB context queries (optional, for searching within context)
CREATE INDEX IF NOT EXISTS idx_logs_context ON logs USING GIN (context);
CREATE INDEX IF NOT EXISTS idx_logs_extra ON logs USING GIN (extra);

-- Query examples:
-- SELECT * FROM logs WHERE channel = 'app' ORDER BY created_at DESC LIMIT 100;
-- SELECT * FROM logs WHERE level_value >= 400 ORDER BY created_at DESC; -- Errors+
-- SELECT * FROM logs WHERE request_id = 'abc-123-def';
-- SELECT * FROM logs WHERE created_at >= NOW() - INTERVAL '1 hour';

-- JSONB queries:
-- SELECT * FROM logs WHERE context->>'user_id' = '123';
-- SELECT * FROM logs WHERE context ? 'exception';
-- SELECT * FROM logs WHERE extra @> '{"hostname": "web-01"}';

-- Partitioning (PostgreSQL 10+):
-- CREATE TABLE logs (
--     ...
-- ) PARTITION BY RANGE (created_at);
--
-- CREATE TABLE logs_2024_01 PARTITION OF logs
--     FOR VALUES FROM ('2024-01-01') TO ('2024-02-01');
