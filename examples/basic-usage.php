<?php

/**
 * Basic Usage Examples
 *
 * Run: php examples/basic-usage.php
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Senza1dio\EnterprisePSR3Logger\Logger;
use Senza1dio\EnterprisePSR3Logger\LoggerFactory;
use Senza1dio\EnterprisePSR3Logger\Handlers\StreamHandler;
use Senza1dio\EnterprisePSR3Logger\Formatters\PrettyFormatter;
use Senza1dio\EnterprisePSR3Logger\Processors\MemoryProcessor;
use Monolog\Level;

echo "=== Enterprise PSR-3 Logger Examples ===\n\n";

// -----------------------------------------------------------------------------
// Example 1: Quick development logger
// -----------------------------------------------------------------------------
echo "--- Example 1: Development Logger ---\n\n";

$devLogger = LoggerFactory::development('my-app');
$devLogger->info('Application started');
$devLogger->debug('Debug information', ['debug_mode' => true]);
$devLogger->warning('This is a warning');
$devLogger->error('Something went wrong', ['error_code' => 500]);

echo "\n";

// -----------------------------------------------------------------------------
// Example 2: Manual configuration
// -----------------------------------------------------------------------------
echo "--- Example 2: Manual Configuration ---\n\n";

$handler = new StreamHandler('php://stdout', Level::Debug);
$handler->setFormatter(new PrettyFormatter(useColors: true));

$logger = new Logger('custom-app', [$handler]);
$logger->addProcessor(new MemoryProcessor(includePeak: true));

$logger->info('Custom logger configured', [
    'handlers' => 1,
    'processors' => 1,
]);

echo "\n";

// -----------------------------------------------------------------------------
// Example 3: Exception logging
// -----------------------------------------------------------------------------
echo "--- Example 3: Exception Logging ---\n\n";

try {
    throw new RuntimeException('Database connection failed', 500);
} catch (Throwable $e) {
    $devLogger->error('Operation failed', [
        'exception' => $e,
        'operation' => 'database_connect',
    ]);
}

echo "\n";

// -----------------------------------------------------------------------------
// Example 4: Context inheritance
// -----------------------------------------------------------------------------
echo "--- Example 4: Context Inheritance ---\n\n";

$baseLogger = LoggerFactory::development('api');
$baseLogger->addGlobalContext('api_version', 'v2');

$userLogger = $baseLogger->withContext(['module' => 'users']);
$userLogger->info('User created', ['user_id' => 123]);

$orderLogger = $baseLogger->withContext(['module' => 'orders']);
$orderLogger->info('Order placed', ['order_id' => 456]);

echo "\n";

// -----------------------------------------------------------------------------
// Example 5: Sampling (reduce log volume)
// -----------------------------------------------------------------------------
echo "--- Example 5: Sampling ---\n\n";

$sampledLogger = LoggerFactory::development('high-traffic');
$sampledLogger->setLevelSamplingRate('debug', 0.1);  // 10% of debug
$sampledLogger->setLevelSamplingRate('info', 0.5);   // 50% of info

for ($i = 1; $i <= 10; $i++) {
    $sampledLogger->debug("Debug message $i (10% chance)");
    $sampledLogger->info("Info message $i (50% chance)");
}

$sampledLogger->error('Errors are always logged');

echo "\n=== Examples Complete ===\n";
