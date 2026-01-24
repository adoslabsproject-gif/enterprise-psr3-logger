-- MySQL/MariaDB Schema for Enterprise PSR-3 Logger
-- Run this to create the logs table in your MySQL database

CREATE TABLE IF NOT EXISTS logs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    channel VARCHAR(255) NOT NULL,
    level VARCHAR(20) NOT NULL,
    level_value SMALLINT UNSIGNED NOT NULL,
    message TEXT NOT NULL,
    context JSON,
    extra JSON,
    created_at DATETIME(6) NOT NULL,
    request_id VARCHAR(36),

    -- Indexes for common queries
    INDEX idx_logs_channel (channel),
    INDEX idx_logs_level (level_value),
    INDEX idx_logs_created_at (created_at),
    INDEX idx_logs_request_id (request_id),

    -- Composite index for channel + time range queries
    INDEX idx_logs_channel_time (channel, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Optional: Partitioning by date for large log volumes
-- ALTER TABLE logs PARTITION BY RANGE (TO_DAYS(created_at)) (
--     PARTITION p_old VALUES LESS THAN (TO_DAYS('2024-01-01')),
--     PARTITION p_2024_01 VALUES LESS THAN (TO_DAYS('2024-02-01')),
--     PARTITION p_2024_02 VALUES LESS THAN (TO_DAYS('2024-03-01')),
--     PARTITION p_future VALUES LESS THAN MAXVALUE
-- );

-- Query examples:
-- SELECT * FROM logs WHERE channel = 'app' ORDER BY created_at DESC LIMIT 100;
-- SELECT * FROM logs WHERE level_value >= 400 ORDER BY created_at DESC; -- Errors+
-- SELECT * FROM logs WHERE request_id = 'abc-123-def';
-- SELECT * FROM logs WHERE created_at >= NOW() - INTERVAL 1 HOUR;
