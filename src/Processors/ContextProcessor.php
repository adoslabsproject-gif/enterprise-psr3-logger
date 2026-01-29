<?php

declare(strict_types=1);

namespace AdosLabs\EnterprisePSR3Logger\Processors;

use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

/**
 * Context Processor
 *
 * Adds static context fields to all log records.
 * Useful for application-wide metadata like app version, deployment ID, etc.
 *
 * USAGE:
 * ```php
 * // Add application metadata
 * $processor = new ContextProcessor([
 *     'app_name' => 'my-service',
 *     'app_version' => '1.2.3',
 *     'deployment_id' => getenv('DEPLOYMENT_ID'),
 * ]);
 *
 * $logger->addProcessor($processor);
 * ```
 *
 * RUNTIME UPDATES:
 * ```php
 * // After authentication
 * $processor->set('user_id', $authenticatedUserId);
 *
 * // Multi-tenant apps
 * $processor->merge(['tenant_id' => $tenantId, 'org_id' => $orgId]);
 *
 * // Reset between requests (long-running processes)
 * $processor->clear();
 * ```
 *
 * PERFORMANCE:
 * - Uses spread operator for O(1) array merging
 * - Early return when context is empty
 * - Context stored by reference (no copy on each log)
 */
final class ContextProcessor implements ProcessorInterface
{
    /** @var array<string, mixed> */
    private array $context;

    private readonly bool $addToExtra;

    /**
     * @param array<string, mixed> $context Initial context values
     * @param bool $addToExtra Add to extra (true) or context (false)
     */
    public function __construct(
        array $context = [],
        bool $addToExtra = true,
    ) {
        $this->context = $context;
        $this->addToExtra = $addToExtra;
    }

    /**
     * Set a context value
     */
    public function set(string $key, mixed $value): self
    {
        $this->context[$key] = $value;

        return $this;
    }

    /**
     * Get a context value
     */
    public function get(string $key): mixed
    {
        return $this->context[$key] ?? null;
    }

    /**
     * Check if a context key exists
     */
    public function has(string $key): bool
    {
        return array_key_exists($key, $this->context);
    }

    /**
     * Merge additional context (new values override existing)
     *
     * @param array<string, mixed> $context
     */
    public function merge(array $context): self
    {
        $this->context = [...$this->context, ...$context];

        return $this;
    }

    /**
     * Remove a context value
     */
    public function remove(string $key): self
    {
        unset($this->context[$key]);

        return $this;
    }

    /**
     * Clear all context
     *
     * Call this between requests in long-running processes
     * (Swoole, RoadRunner, ReactPHP).
     */
    public function clear(): self
    {
        $this->context = [];

        return $this;
    }

    /**
     * Get all context values
     *
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return $this->context;
    }

    /**
     * Check if context is empty
     */
    public function isEmpty(): bool
    {
        return empty($this->context);
    }

    /**
     * Process log record
     *
     * PERFORMANCE: Uses spread operator for O(1) merging.
     * Processor context comes FIRST so record values can override.
     */
    public function __invoke(LogRecord $record): LogRecord
    {
        if (empty($this->context)) {
            return $record;
        }

        if ($this->addToExtra) {
            // Processor context first, record extra overrides
            return $record->with(extra: [...$this->context, ...$record->extra]);
        }

        // Processor context first, record context overrides
        return $record->with(context: [...$this->context, ...$record->context]);
    }
}
