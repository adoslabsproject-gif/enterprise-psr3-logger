<?php

/**
 * ENTERPRISE PSR-3 LOGGER: Channel-Based Syntax Example
 *
 * This example shows how to use the LoggerFacade with clean channel-based syntax:
 * Logger::channel($level, $message, $context)
 */

declare(strict_types=1);

// Step 1: Define should_log() BEFORE Composer autoload
function should_log(string $channel, string $level): bool
{
    echo "[should_log] channel={$channel}, level={$level}\n";

    // Example: Log warnings and above in production
    if (getenv('APP_ENV') === 'production') {
        return in_array($level, ['warning', 'error', 'critical', 'alert', 'emergency'], true);
    }

    // Development: Log everything
    return true;
}

// Step 2: Load Composer autoload
require __DIR__ . '/../vendor/autoload.php';

// Step 3: Setup LoggerFacade (alias as Logger for clean syntax)
use Senza1dio\EnterprisePSR3Logger\LoggerFacade as Logger;
use Senza1dio\EnterprisePSR3Logger\LoggerFactory;
use Senza1dio\EnterprisePSR3Logger\LoggerRegistry;

// Step 4: Register channels
LoggerRegistry::register(LoggerFactory::production('default', '/tmp/logs'), 'default');
LoggerRegistry::register(LoggerFactory::production('security', '/tmp/logs'), 'security');
LoggerRegistry::register(LoggerFactory::production('api', '/tmp/logs'), 'api');
LoggerRegistry::register(LoggerFactory::production('database', '/tmp/logs'), 'database');
LoggerRegistry::register(LoggerFactory::production('email', '/tmp/logs'), 'email');
LoggerRegistry::register(LoggerFactory::production('debug_general', '/tmp/logs'), 'debug_general');

echo "\n=== CHANNEL-BASED LOGGING SYNTAX ===\n\n";

// ============================================================================
// EXAMPLE 1: Channel-based logging (clean syntax)
// ============================================================================

echo "EXAMPLE 1: Channel-based logging\n";
echo "---------------------------------\n";

// ✅ Clean syntax: Logger::channel($level, $message, $context)
Logger::security('warning', 'Failed login attempt', [
    'ip' => '1.2.3.4',
    'username' => 'admin',
    'attempts' => 5,
]);

Logger::api('info', 'HTTP Request', [
    'method' => 'GET',
    'uri' => '/api/users',
    'status' => 200,
]);

Logger::database('error', 'Query failed', [
    'query' => 'SELECT * FROM users WHERE id = ?',
    'error' => 'Connection timeout',
]);

Logger::email('debug', 'Email sent successfully', [
    'to' => 'user@example.com',
    'subject' => 'Welcome!',
]);

echo "  ✅ Logged to: security, api, database, email channels\n\n";

// ============================================================================
// EXAMPLE 2: Convenience methods (channel = 'default')
// ============================================================================

echo "EXAMPLE 2: Convenience methods (default channel)\n";
echo "------------------------------------------------\n";

Logger::error('Database connection failed', [
    'host' => 'db.example.com',
    'port' => 5432,
]);

Logger::warning('Cache miss detected', [
    'key' => 'user:123',
]);

Logger::info('User logged in', [
    'user_id' => 123,
    'session_id' => 'abc-123-def',
]);

Logger::debug('Session synced', [
    'session_id' => 'abc-123-def',
]);

echo "  ✅ Logged to: default channel (error, warning, info, debug)\n\n";

// ============================================================================
// EXAMPLE 3: Custom channel
// ============================================================================

echo "EXAMPLE 3: Custom channel\n";
echo "-------------------------\n";

Logger::channel('custom', 'info', 'Custom event', [
    'event' => 'user_registered',
    'timestamp' => time(),
]);

echo "  ✅ Logged to: custom channel\n\n";

// ============================================================================
// EXAMPLE 4: Verify should_log() is called
// ============================================================================

echo "EXAMPLE 4: Verify should_log() is called for each log\n";
echo "------------------------------------------------------\n";

// This should call should_log() multiple times
Logger::security('debug', 'Debug message 1');
Logger::security('info', 'Info message 1');
Logger::security('warning', 'Warning message 1');

echo "  ✅ should_log() called for each log above\n\n";

// ============================================================================
// SYNTAX COMPARISON
// ============================================================================

echo "===========================================\n";
echo "✅ SYNTAX EXAMPLES\n";
echo "===========================================\n\n";

echo "Channel-based syntax:\n";
echo "---------------------\n";
echo "Logger::security('warning', 'Message', ['context']);\n";
echo "Logger::api('info', 'Message', ['context']);\n";
echo "Logger::database('error', 'Message', ['context']);\n\n";

echo "Convenience methods:\n";
echo "--------------------\n";
echo "Logger::error('Message', ['context']);  // channel = 'default'\n";
echo "Logger::warning('Message', ['context']);\n";
echo "Logger::info('Message', ['context']);\n\n";

echo "Custom channel:\n";
echo "---------------\n";
echo "Logger::channel('custom', 'info', 'Message', ['context']);\n\n";

// ============================================================================
// SETUP INSTRUCTIONS
// ============================================================================

echo "===========================================\n";
echo "📦 SETUP INSTRUCTIONS\n";
echo "===========================================\n\n";

echo "Step 1: Install package\n";
echo "-----------------------\n";
echo "composer require senza1dio/enterprise-psr3-logger\n\n";

echo "Step 2: Define should_log() function\n";
echo "-------------------------------------\n";
echo "// bootstrap.php\n";
echo "function should_log(string \$channel, string \$level): bool {\n";
echo "    // Your custom logic\n";
echo "    return true;\n";
echo "}\n\n";

echo "Step 3: Load Composer autoload\n";
echo "-------------------------------\n";
echo "require 'vendor/autoload.php';\n\n";

echo "Step 4: Setup channels\n";
echo "-----------------------\n";
echo "use Senza1dio\\EnterprisePSR3Logger\\{LoggerFactory, LoggerRegistry, LoggerFacade as Logger};\n\n";
echo "LoggerRegistry::register(LoggerFactory::production('security', '/var/log'), 'security');\n";
echo "LoggerRegistry::register(LoggerFactory::production('api', '/var/log'), 'api');\n";
echo "// ... repeat for all channels\n\n";

echo "Step 5: Use it!\n";
echo "---------------\n";
echo "Logger::security('warning', 'Message', ['context']);\n";
echo "Logger::api('info', 'Message', ['context']);\n\n";
