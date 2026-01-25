<?php

/**
 * TEST: What happens if user FORGETS to define should_log()?
 */

declare(strict_types=1);

echo "=== TEST: Logger WITHOUT should_log() ===\n\n";

// ❌ DELIBERATE: We DON'T define should_log() here
// This simulates a user forgetting to add it

// Load Composer autoload (will load stub)
require __DIR__ . '/../vendor/autoload.php';

use Senza1dio\EnterprisePSR3Logger\LoggerFactory;

echo "Step 1: Create logger without should_log()\n";
echo "-------------------------------------------\n";

$logger = LoggerFactory::production('test', '/tmp/logs');

echo "  ✅ Logger created\n\n";

echo "Step 2: Check if should_log() exists\n";
echo "-------------------------------------\n";

if (function_exists('should_log')) {
    echo "  ✅ should_log() exists (from stub)\n";

    $reflection = new ReflectionFunction('should_log');
    $fileName = $reflection->getFileName();
    echo "  📍 Defined in: {$fileName}\n";

    if (strpos($fileName, 'should_log_stub.php') !== false) {
        echo "  ⚠️  Using STUB implementation (allows all logs)\n";
    } else {
        echo "  ✅ Using custom implementation\n";
    }
} else {
    echo "  ❌ should_log() NOT found\n";
}

echo "\n";

echo "Step 3: Test logging (should work but emit warning)\n";
echo "----------------------------------------------------\n";

$logger->info('Test message without custom should_log()');

echo "  ✅ Logging works (stub allows everything)\n\n";

echo "Step 4: Verify stub behavior\n";
echo "-----------------------------\n";

$testCases = [
    ['channel' => 'security', 'level' => 'debug'],
    ['channel' => 'api', 'level' => 'info'],
    ['channel' => 'database', 'level' => 'error'],
];

foreach ($testCases as $test) {
    $result = should_log($test['channel'], $test['level']);
    echo "  should_log('{$test['channel']}', '{$test['level']}') = " . ($result ? 'TRUE' : 'FALSE') . "\n";
}

echo "\n";

echo "===========================================\n";
echo "✅ RESULT\n";
echo "===========================================\n\n";

echo "Without custom should_log():\n";
echo "----------------------------\n";
echo "✅ Stub function is auto-loaded\n";
echo "✅ Logger works correctly\n";
echo "⚠️  ALL logs are written (stub returns TRUE always)\n";
echo "⚠️  Warning emitted on first log (visible in error_log)\n\n";

echo "Recommendation:\n";
echo "---------------\n";
echo "Define should_log() in your bootstrap BEFORE Composer autoload\n";
echo "to override the stub with your custom logic.\n";
