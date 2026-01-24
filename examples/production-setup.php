<?php

/**
 * Production Setup Example
 *
 * Shows how to configure logging for production environments.
 *
 * Run: php examples/production-setup.php
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Senza1dio\EnterprisePSR3Logger\Logger;
use Senza1dio\EnterprisePSR3Logger\LoggerFactory;
use Senza1dio\EnterprisePSR3Logger\LoggerManager;
use Senza1dio\EnterprisePSR3Logger\Handlers\StreamHandler;
use Senza1dio\EnterprisePSR3Logger\Handlers\RotatingFileHandler;
use Senza1dio\EnterprisePSR3Logger\Handlers\FilterHandler;
use Senza1dio\EnterprisePSR3Logger\Handlers\BufferHandler;
use Senza1dio\EnterprisePSR3Logger\Formatters\JsonFormatter;
use Senza1dio\EnterprisePSR3Logger\Processors\RequestProcessor;
use Senza1dio\EnterprisePSR3Logger\Processors\HostnameProcessor;
use Senza1dio\EnterprisePSR3Logger\Processors\ExecutionTimeProcessor;
use Monolog\Level;

echo "=== Production Setup Examples ===\n\n";

// Use temp directory for examples
$logDir = sys_get_temp_dir() . '/enterprise-logger-example';
if (!is_dir($logDir)) {
    mkdir($logDir, 0755, true);
}

// -----------------------------------------------------------------------------
// Example 1: Production factory method
// -----------------------------------------------------------------------------
echo "--- Example 1: Production Logger (Factory) ---\n";
echo "Log directory: $logDir\n\n";

$prodLogger = LoggerFactory::production(
    channel: 'my-app',
    logDir: $logDir,
    maxFiles: 14,
    compress: true
);

$prodLogger->info('Application started', ['version' => '1.0.0']);
$prodLogger->error('Database connection failed', ['host' => 'db.example.com']);

echo "Logs written to: $logDir\n";
echo "Files created:\n";
foreach (glob("$logDir/*.log") as $file) {
    echo "  - " . basename($file) . " (" . filesize($file) . " bytes)\n";
}
echo "\n";

// -----------------------------------------------------------------------------
// Example 2: Multi-channel setup
// -----------------------------------------------------------------------------
echo "--- Example 2: Multi-Channel Manager ---\n\n";

$manager = new LoggerManager();

// Default handler for all channels
$defaultHandler = new StreamHandler('php://stdout', Level::Info);
$defaultHandler->setFormatter(new JsonFormatter());
$manager->setDefaultHandler($defaultHandler);

// Security channel with separate file
$securityHandler = new RotatingFileHandler(
    filename: "$logDir/security.log",
    level: Level::Warning,
    rotationType: RotatingFileHandler::ROTATION_DAILY,
    maxFiles: 30
);
$securityHandler->setFormatter(new JsonFormatter());
$manager->setChannelHandlers('security', [$securityHandler]);

// Global context for all loggers
$manager->setGlobalContext([
    'environment' => 'production',
    'server' => gethostname(),
]);

// Get channel loggers
$appLog = $manager->channel('app');
$securityLog = $manager->channel('security');
$auditLog = $manager->channel('audit');

$appLog->info('App channel log');
$securityLog->warning('Security event', ['ip' => '192.168.1.1']);
$auditLog->info('Audit trail entry', ['user_id' => 123]);

echo "\nChannels active: " . implode(', ', $manager->getChannels()) . "\n\n";

// -----------------------------------------------------------------------------
// Example 3: Buffered logging
// -----------------------------------------------------------------------------
echo "--- Example 3: Buffered Logging ---\n\n";

$innerHandler = new StreamHandler('php://stdout', Level::Debug);
$innerHandler->setFormatter(new JsonFormatter());

$bufferedHandler = new BufferHandler(
    handler: $innerHandler,
    bufferLimit: 100,
    flushOnOverflow: true,
    flushOnError: true,  // Flush immediately on errors
    flushOnShutdown: true
);

$bufferedLogger = new Logger('buffered', [$bufferedHandler]);

$bufferedLogger->debug('Buffered message 1');
$bufferedLogger->debug('Buffered message 2');
$bufferedLogger->debug('Buffered message 3');
echo "Buffer size: " . $bufferedHandler->getBufferSize() . "\n";

$bufferedLogger->error('Error triggers flush');
echo "Buffer size after error: " . $bufferedHandler->getBufferSize() . "\n\n";

// -----------------------------------------------------------------------------
// Example 4: Level-based routing
// -----------------------------------------------------------------------------
echo "--- Example 4: Level-Based Routing ---\n\n";

// Error handler (ERROR and above)
$errorHandler = new FilterHandler(
    new StreamHandler("$logDir/error.log", Level::Error),
    minLevel: Level::Error
);

// Info handler (INFO to WARNING)
$infoHandler = new FilterHandler(
    new StreamHandler("$logDir/info.log", Level::Info),
    minLevel: Level::Info,
    maxLevel: Level::Warning
);

// Debug handler (DEBUG only, for development)
$debugHandler = new FilterHandler(
    new StreamHandler("$logDir/debug.log", Level::Debug),
    minLevel: Level::Debug,
    maxLevel: Level::Debug
);

$routedLogger = new Logger('routed', [$errorHandler, $infoHandler, $debugHandler]);

$routedLogger->debug('Goes to debug.log only');
$routedLogger->info('Goes to info.log only');
$routedLogger->warning('Goes to info.log only');
$routedLogger->error('Goes to error.log only');
$routedLogger->critical('Goes to error.log only');

echo "Log files:\n";
foreach (glob("$logDir/*.log") as $file) {
    echo "  - " . basename($file) . " (" . filesize($file) . " bytes)\n";
}
echo "\n";

// -----------------------------------------------------------------------------
// Example 5: Container/Docker setup
// -----------------------------------------------------------------------------
echo "--- Example 5: Container Setup ---\n\n";

$containerLogger = LoggerFactory::container(
    channel: 'api',
    minLevel: Level::Info,
    environment: 'kubernetes'
);

$containerLogger->info('Container log entry', [
    'pod' => 'api-7d8b9c-xyz',
    'namespace' => 'production',
]);

echo "\n";

// -----------------------------------------------------------------------------
// Cleanup
// -----------------------------------------------------------------------------
echo "--- Cleanup ---\n";
echo "Removing temporary log files...\n";

$manager->closeAll();
$bufferedHandler->close();

foreach (glob("$logDir/*") as $file) {
    unlink($file);
}
rmdir($logDir);

echo "Done.\n\n";
echo "=== Examples Complete ===\n";
