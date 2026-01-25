<?php

declare(strict_types=1);

namespace AdosLabs\EnterprisePSR3Logger\Processors;

use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

/**
 * Context Processor
 *
 * Adds static context fields to all log records.
 * Useful for application-wide metadata.
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
 * The context can be updated at runtime:
 * ```php
 * $processor->set('user_id', $authenticatedUserId);
 * $processor->merge(['tenant_id' => $tenantId, 'org_id' => $orgId]);
 * ```
 */
class ContextProcessor implements ProcessorInterface
{
    /** @var array<string, mixed> */
    private array $context;

    private bool $addToExtra;

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
     * Merge additional context
     *
     * @param array<string, mixed> $context
     */
    public function merge(array $context): self
    {
        $this->context = array_merge($this->context, $context);

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
     * Process log record
     */
    public function __invoke(LogRecord $record): LogRecord
    {
        if (empty($this->context)) {
            return $record;
        }

        if ($this->addToExtra) {
            return $record->with(extra: array_merge($this->context, $record->extra));
        }

        return $record->with(context: array_merge($this->context, $record->context));
    }
}
