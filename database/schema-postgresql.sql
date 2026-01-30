-- ============================================================================
-- PostgreSQL Schema for Enterprise PSR-3 Logger - DATABASE HANDLER (OPTIONAL)
-- ============================================================================
-- !!! IMPORTANT !!!
-- This schema is ONLY needed if you want to use the DatabaseHandler.
-- By default, PSR3 Logger uses FILE-BASED logging (RotatingFileHandler).
--
-- DO NOT run this migration unless you specifically need database logging.
-- The default file-based logging requires NO database tables.
--
-- Version: 2.0.0 - Enterprise Grade with full indexing
-- ============================================================================

CREATE TABLE IF NOT EXISTS logs (
    id BIGSERIAL PRIMARY KEY,
    channel VARCHAR(100) NOT NULL,
    level VARCHAR(20) NOT NULL,
    level_value SMALLINT NOT NULL,
    message TEXT NOT NULL,
    context JSONB DEFAULT '{}'::JSONB,
    extra JSONB DEFAULT '{}'::JSONB,
    request_id VARCHAR(64),
    user_id BIGINT,
    ip_address VARCHAR(45),
    user_agent TEXT,
    created_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- ============================================================================
-- PRIMARY INDEXES (most common queries)
-- ============================================================================

-- Single column indexes for common filters
CREATE INDEX IF NOT EXISTS idx_logs_channel ON logs(channel);
CREATE INDEX IF NOT EXISTS idx_logs_level ON logs(level_value);
CREATE INDEX IF NOT EXISTS idx_logs_created_at ON logs(created_at DESC);
CREATE INDEX IF NOT EXISTS idx_logs_request_id ON logs(request_id);
CREATE INDEX IF NOT EXISTS idx_logs_user_id ON logs(user_id);

-- ============================================================================
-- COMPOSITE INDEXES (optimized for dashboard queries)
-- ============================================================================

-- Channel + time range queries (most common dashboard query)
CREATE INDEX IF NOT EXISTS idx_logs_channel_time ON logs(channel, created_at DESC);

-- Level + time range (error monitoring)
CREATE INDEX IF NOT EXISTS idx_logs_level_time ON logs(level_value, created_at DESC);

-- Channel + level + time (filtered error monitoring per channel)
CREATE INDEX IF NOT EXISTS idx_logs_channel_level_time ON logs(channel, level_value, created_at DESC);

-- User tracking
CREATE INDEX IF NOT EXISTS idx_logs_user_time ON logs(user_id, created_at DESC) WHERE user_id IS NOT NULL;

-- IP tracking (security)
CREATE INDEX IF NOT EXISTS idx_logs_ip ON logs(ip_address) WHERE ip_address IS NOT NULL;

-- ============================================================================
-- JSONB INDEXES (for context searching)
-- ============================================================================

-- GIN index for JSONB context queries (searching within context)
CREATE INDEX IF NOT EXISTS idx_logs_context ON logs USING GIN (context jsonb_path_ops);
CREATE INDEX IF NOT EXISTS idx_logs_extra ON logs USING GIN (extra jsonb_path_ops);

-- ============================================================================
-- PARTITIONING RECOMMENDATION (for high-volume production)
-- ============================================================================
-- For very high log volumes, consider partitioning by month:
--
-- CREATE TABLE logs (
--     id BIGSERIAL,
--     channel VARCHAR(100) NOT NULL,
--     level VARCHAR(20) NOT NULL,
--     level_value SMALLINT NOT NULL,
--     message TEXT NOT NULL,
--     context JSONB DEFAULT '{}'::JSONB,
--     extra JSONB DEFAULT '{}'::JSONB,
--     request_id VARCHAR(64),
--     user_id BIGINT,
--     ip_address VARCHAR(45),
--     user_agent TEXT,
--     created_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP,
--     PRIMARY KEY (id, created_at)
-- ) PARTITION BY RANGE (created_at);
--
-- CREATE TABLE logs_2026_01 PARTITION OF logs
--     FOR VALUES FROM ('2026-01-01') TO ('2026-02-01');
--
-- ============================================================================

-- ============================================================================
-- QUERY EXAMPLES
-- ============================================================================
-- SELECT * FROM logs WHERE channel = 'app' ORDER BY created_at DESC LIMIT 100;
-- SELECT * FROM logs WHERE level_value >= 400 ORDER BY created_at DESC; -- Errors+
-- SELECT * FROM logs WHERE request_id = 'abc-123-def';
-- SELECT * FROM logs WHERE created_at >= NOW() - INTERVAL '1 hour';
-- SELECT * FROM logs WHERE user_id = 123 ORDER BY created_at DESC;
--
-- JSONB queries:
-- SELECT * FROM logs WHERE context->>'user_id' = '123';
-- SELECT * FROM logs WHERE context ? 'exception';
-- SELECT * FROM logs WHERE extra @> '{"hostname": "web-01"}';
-- ============================================================================

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
