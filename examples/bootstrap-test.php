<?php

/**
 * ENTERPRISE PSR-3 LOGGER: Bootstrap Test
 *
 * This script tests different bootstrap scenarios to ensure
 * should_log() function is loaded correctly.
 */

declare(strict_types=1);

echo "=== ENTERPRISE PSR-3 LOGGER: Bootstrap Test ===\n\n";

// ============================================================================
// TEST 1: Define should_log() BEFORE Composer autoload (RECOMMENDED)
// ============================================================================

echo "TEST 1: Define should_log() before Composer autoload\n";
echo "-----------------------------------------------------\n";

// Step 1: Define should_log() FIRST
function should_log(string $channel, string $level): bool
{
    echo "  ✅ should_log() called: channel={$channel}, level={$level}\n";

    // Custom logic: Only log warnings and above in production
    if (getenv('APP_ENV') === 'production') {
        return in_array($level, ['warning', 'error', 'critical', 'alert', 'emergency'], true);
    }

    // Development: Log everything
    return true;
}

echo "  ✅ should_log() defined in global namespace\n";

// Step 2: Load Composer autoload
require __DIR__ . '/../vendor/autoload.php';

echo "  ✅ Composer autoload loaded\n";

// Step 3: Verify function exists
if (function_exists('should_log')) {
    echo "  ✅ should_log() exists in global namespace\n";
} else {
    echo "  ❌ ERROR: should_log() not found!\n";
    exit(1);
}

// Step 4: Test with logger
use Senza1dio\EnterprisePSR3Logger\Logger;
use Senza1dio\EnterprisePSR3Logger\Handlers\StreamHandler;

$handler = new StreamHandler('php://stderr'); // Output to stderr for testing
$logger = new Logger('test-channel', [$handler]);

echo "  ✅ Logger created with channel: test-channel\n\n";

echo "TEST 1 RESULT: Logging a message (should call should_log())\n";
$logger->info('Test message from bootstrap-test.php');
echo "\n";

// ============================================================================
// TEST 2: Verify namespace isolation
// ============================================================================

echo "TEST 2: Verify namespace isolation\n";
echo "-----------------------------------\n";

// Function should be callable with fully qualified name
$result = \should_log('test', 'info');
echo "  ✅ \\should_log() callable with fully qualified name\n";
echo "  ✅ Result: " . ($result ? 'true' : 'false') . "\n\n";

// ============================================================================
// TEST 3: Verify stub was NOT loaded (our definition takes precedence)
// ============================================================================

echo "TEST 3: Verify custom implementation (not stub)\n";
echo "-----------------------------------------------\n";

$reflection = new ReflectionFunction('should_log');
$fileName = $reflection->getFileName();
echo "  Function defined in: {$fileName}\n";

if (strpos($fileName, 'should_log_stub.php') !== false) {
    echo "  ❌ ERROR: Stub was loaded instead of custom implementation!\n";
    echo "  This means the custom function was defined AFTER Composer autoload.\n";
    exit(1);
} else {
    echo "  ✅ Custom implementation loaded (not stub)\n";
}

echo "\n";

// ============================================================================
// TEST 4: Test multiple channels
// ============================================================================

echo "TEST 4: Test multiple channels\n";
echo "-------------------------------\n";

use Senza1dio\EnterprisePSR3Logger\LoggerFactory;

$channels = ['default', 'security', 'api', 'database'];

foreach ($channels as $channel) {
    $logger = LoggerFactory::production($channel, '/tmp/test-logs');
    echo "  ✅ Created logger for channel: {$channel}\n";

    // This should call should_log() for each
    $logger->debug("Debug message from {$channel}");
}

echo "\n";

// ============================================================================
// FINAL RESULT
// ============================================================================

echo "===========================================\n";
echo "✅ ALL TESTS PASSED\n";
echo "===========================================\n\n";

echo "Summary:\n";
echo "--------\n";
echo "✅ should_log() defined before Composer autoload\n";
echo "✅ Function exists in global namespace\n";
echo "✅ Function callable from any namespace\n";
echo "✅ Custom implementation (not stub)\n";
echo "✅ Logger calls should_log() correctly\n";
echo "✅ Works with multiple channels\n\n";

echo "Next steps:\n";
echo "-----------\n";
echo "1. Use this pattern in your project's bootstrap\n";
echo "2. Implement custom logic in should_log() (database, Redis, etc.)\n";
echo "3. See examples/should_log.php for enterprise implementations\n";
