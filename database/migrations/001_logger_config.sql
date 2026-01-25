-- ============================================================================
-- Enterprise PSR3 Logger - Configuration Migration
-- ============================================================================
-- This migration adds logging configuration to admin_config table
-- Used by enterprise-admin-panel for centralized settings management
-- ============================================================================

-- ============================================================================
-- LOG CHANNELS CONFIGURATION
-- ============================================================================
-- Each channel has: enabled (bool), level (string)
-- Levels: debug, info, notice, warning, error, critical, alert, emergency

-- General Debug Channel (debug, info, notice)
INSERT INTO admin_config (config_key, config_value, value_type, description, is_sensitive, is_editable)
VALUES ('log_channel_debug_general_enabled', 'true', 'boolean', 'Enable general debug logging channel', false, true)
ON CONFLICT (config_key) DO NOTHING;

INSERT INTO admin_config (config_key, config_value, value_type, description, is_sensitive, is_editable)
VALUES ('log_channel_debug_general_level', 'debug', 'string', 'Minimum level for debug_general channel (debug, info, notice)', false, true)
ON CONFLICT (config_key) DO NOTHING;

-- General Error Channel (warning to emergency)
INSERT INTO admin_config (config_key, config_value, value_type, description, is_sensitive, is_editable)
VALUES ('log_channel_error_general_enabled', 'true', 'boolean', 'Enable general error logging channel', false, true)
ON CONFLICT (config_key) DO NOTHING;

INSERT INTO admin_config (config_key, config_value, value_type, description, is_sensitive, is_editable)
VALUES ('log_channel_error_general_level', 'warning', 'string', 'Minimum level for error_general channel (warning+)', false, true)
ON CONFLICT (config_key) DO NOTHING;

-- API Channel
INSERT INTO admin_config (config_key, config_value, value_type, description, is_sensitive, is_editable)
VALUES ('log_channel_api_enabled', 'true', 'boolean', 'Enable API logging channel', false, true)
ON CONFLICT (config_key) DO NOTHING;

INSERT INTO admin_config (config_key, config_value, value_type, description, is_sensitive, is_editable)
VALUES ('log_channel_api_level', 'info', 'string', 'Minimum level for API channel', false, true)
ON CONFLICT (config_key) DO NOTHING;

-- Security Channel
INSERT INTO admin_config (config_key, config_value, value_type, description, is_sensitive, is_editable)
VALUES ('log_channel_security_enabled', 'true', 'boolean', 'Enable security logging channel', false, true)
ON CONFLICT (config_key) DO NOTHING;

INSERT INTO admin_config (config_key, config_value, value_type, description, is_sensitive, is_editable)
VALUES ('log_channel_security_level', 'warning', 'string', 'Minimum level for security channel', false, true)
ON CONFLICT (config_key) DO NOTHING;

-- Database Channel
INSERT INTO admin_config (config_key, config_value, value_type, description, is_sensitive, is_editable)
VALUES ('log_channel_database_enabled', 'true', 'boolean', 'Enable database logging channel', false, true)
ON CONFLICT (config_key) DO NOTHING;

INSERT INTO admin_config (config_key, config_value, value_type, description, is_sensitive, is_editable)
VALUES ('log_channel_database_level', 'error', 'string', 'Minimum level for database channel', false, true)
ON CONFLICT (config_key) DO NOTHING;

-- Auth Channel
INSERT INTO admin_config (config_key, config_value, value_type, description, is_sensitive, is_editable)
VALUES ('log_channel_auth_enabled', 'true', 'boolean', 'Enable authentication logging channel', false, true)
ON CONFLICT (config_key) DO NOTHING;

INSERT INTO admin_config (config_key, config_value, value_type, description, is_sensitive, is_editable)
VALUES ('log_channel_auth_level', 'info', 'string', 'Minimum level for auth channel', false, true)
ON CONFLICT (config_key) DO NOTHING;

-- Queue/Jobs Channel
INSERT INTO admin_config (config_key, config_value, value_type, description, is_sensitive, is_editable)
VALUES ('log_channel_queue_enabled', 'true', 'boolean', 'Enable queue/jobs logging channel', false, true)
ON CONFLICT (config_key) DO NOTHING;

INSERT INTO admin_config (config_key, config_value, value_type, description, is_sensitive, is_editable)
VALUES ('log_channel_queue_level', 'info', 'string', 'Minimum level for queue channel', false, true)
ON CONFLICT (config_key) DO NOTHING;

-- Mail Channel
INSERT INTO admin_config (config_key, config_value, value_type, description, is_sensitive, is_editable)
VALUES ('log_channel_mail_enabled', 'true', 'boolean', 'Enable mail logging channel', false, true)
ON CONFLICT (config_key) DO NOTHING;

INSERT INTO admin_config (config_key, config_value, value_type, description, is_sensitive, is_editable)
VALUES ('log_channel_mail_level', 'info', 'string', 'Minimum level for mail channel', false, true)
ON CONFLICT (config_key) DO NOTHING;

-- Cache Channel
INSERT INTO admin_config (config_key, config_value, value_type, description, is_sensitive, is_editable)
VALUES ('log_channel_cache_enabled', 'false', 'boolean', 'Enable cache logging channel (verbose)', false, true)
ON CONFLICT (config_key) DO NOTHING;

INSERT INTO admin_config (config_key, config_value, value_type, description, is_sensitive, is_editable)
VALUES ('log_channel_cache_level', 'debug', 'string', 'Minimum level for cache channel', false, true)
ON CONFLICT (config_key) DO NOTHING;

-- Performance Channel
INSERT INTO admin_config (config_key, config_value, value_type, description, is_sensitive, is_editable)
VALUES ('log_channel_performance_enabled', 'false', 'boolean', 'Enable performance logging channel', false, true)
ON CONFLICT (config_key) DO NOTHING;

INSERT INTO admin_config (config_key, config_value, value_type, description, is_sensitive, is_editable)
VALUES ('log_channel_performance_level', 'info', 'string', 'Minimum level for performance channel', false, true)
ON CONFLICT (config_key) DO NOTHING;

-- ============================================================================
-- TELEGRAM NOTIFICATIONS
-- ============================================================================

INSERT INTO admin_config (config_key, config_value, value_type, description, is_sensitive, is_editable)
VALUES ('log_telegram_enabled', 'false', 'boolean', 'Enable Telegram log notifications', false, true)
ON CONFLICT (config_key) DO NOTHING;

INSERT INTO admin_config (config_key, config_value, value_type, description, is_sensitive, is_editable)
VALUES ('log_telegram_bot_token', '', 'string', 'Telegram Bot API Token', true, true)
ON CONFLICT (config_key) DO NOTHING;

INSERT INTO admin_config (config_key, config_value, value_type, description, is_sensitive, is_editable)
VALUES ('log_telegram_chat_id', '', 'string', 'Telegram Chat ID for notifications', true, true)
ON CONFLICT (config_key) DO NOTHING;

INSERT INTO admin_config (config_key, config_value, value_type, description, is_sensitive, is_editable)
VALUES ('log_telegram_level', 'error', 'string', 'Minimum level for Telegram notifications (independent from channel levels)', false, true)
ON CONFLICT (config_key) DO NOTHING;

INSERT INTO admin_config (config_key, config_value, value_type, description, is_sensitive, is_editable)
VALUES ('log_telegram_channels', '["error_general","security"]', 'json', 'Channels to send to Telegram (JSON array, or ["*"] for all)', false, true)
ON CONFLICT (config_key) DO NOTHING;

INSERT INTO admin_config (config_key, config_value, value_type, description, is_sensitive, is_editable)
VALUES ('log_telegram_rate_limit', '10', 'integer', 'Max Telegram messages per minute', false, true)
ON CONFLICT (config_key) DO NOTHING;

-- ============================================================================
-- LOG FILES CONFIGURATION
-- ============================================================================

INSERT INTO admin_config (config_key, config_value, value_type, description, is_sensitive, is_editable)
VALUES ('log_files_directory', 'storage/logs', 'string', 'Directory for log files (relative to project root)', false, true)
ON CONFLICT (config_key) DO NOTHING;

INSERT INTO admin_config (config_key, config_value, value_type, description, is_sensitive, is_editable)
VALUES ('log_files_retention_days', '30', 'integer', 'Days to keep log files before auto-deletion', false, true)
ON CONFLICT (config_key) DO NOTHING;

INSERT INTO admin_config (config_key, config_value, value_type, description, is_sensitive, is_editable)
VALUES ('log_php_errors_file', 'storage/logs/php_errors.log', 'string', 'Path to PHP errors log file', false, true)
ON CONFLICT (config_key) DO NOTHING;

-- ============================================================================
-- GENERAL LOGGING SETTINGS
-- ============================================================================

INSERT INTO admin_config (config_key, config_value, value_type, description, is_sensitive, is_editable)
VALUES ('log_to_database', 'true', 'boolean', 'Store logs in database (required for log viewer)', false, true)
ON CONFLICT (config_key) DO NOTHING;

INSERT INTO admin_config (config_key, config_value, value_type, description, is_sensitive, is_editable)
VALUES ('log_to_files', 'true', 'boolean', 'Write logs to daily files', false, true)
ON CONFLICT (config_key) DO NOTHING;

INSERT INTO admin_config (config_key, config_value, value_type, description, is_sensitive, is_editable)
VALUES ('log_database_retention_days', '7', 'integer', 'Days to keep logs in database', false, true)
ON CONFLICT (config_key) DO NOTHING;
