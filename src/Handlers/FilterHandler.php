<?php

declare(strict_types=1);

namespace AdosLabs\EnterprisePSR3Logger\Handlers;

use Monolog\Handler\HandlerInterface;
use Monolog\Level;
use Monolog\LogRecord;

/**
 * Filter Handler
 *
 * Wraps another handler and filters records based on level range or custom criteria.
 * Useful for routing different log levels to different destinations.
 *
 * USAGE:
 * ```php
 * // Only handle INFO and WARNING (not DEBUG, not ERROR+)
 * $handler = new FilterHandler(
 *     new StreamHandler('/var/log/info.log'),
 *     minLevel: Level::Info,
 *     maxLevel: Level::Warning
 * );
 *
 * // With custom filter
 * $handler = new FilterHandler(
 *     new StreamHandler('/var/log/slow-queries.log'),
 *     filter: fn(LogRecord $record) => ($record->context['duration_ms'] ?? 0) > 1000
 * );
 * ```
 *
 * EXAMPLE SETUP for log separation:
 * ```php
 * $logger->addHandler(new FilterHandler(
 *     new StreamHandler('/var/log/error.log'),
 *     minLevel: Level::Error
 * ));
 *
 * $logger->addHandler(new FilterHandler(
 *     new StreamHandler('/var/log/info.log'),
 *     minLevel: Level::Info,
 *     maxLevel: Level::Warning
 * ));
 *
 * $logger->addHandler(new FilterHandler(
 *     new StreamHandler('/var/log/debug.log'),
 *     minLevel: Level::Debug,
 *     maxLevel: Level::Debug
 * ));
 * ```
 */
class FilterHandler implements HandlerInterface
{
    private HandlerInterface $handler;
    private Level $minLevel;
    private ?Level $maxLevel;

    /** @var callable|null */
    private $filter;

    private bool $bubble;

    /**
     * @param HandlerInterface $handler Handler to wrap
     * @param Level $minLevel Minimum level to pass through
     * @param Level|null $maxLevel Maximum level to pass through (null = no limit)
     * @param callable|null $filter Custom filter function (receives LogRecord, returns bool)
     * @param bool $bubble Whether to bubble to next handler
     */
    public function __construct(
        HandlerInterface $handler,
        Level $minLevel = Level::Debug,
        ?Level $maxLevel = null,
        ?callable $filter = null,
        bool $bubble = true,
    ) {
        $this->handler = $handler;
        $this->minLevel = $minLevel;
        $this->maxLevel = $maxLevel;
        $this->filter = $filter;
        $this->bubble = $bubble;
    }

    /**
     * {@inheritdoc}
     */
    public function isHandling(LogRecord $record): bool
    {
        return $this->shouldHandle($record) && $this->handler->isHandling($record);
    }

    /**
     * {@inheritdoc}
     *
     * Bubble semantics:
     * - If filter doesn't match: always bubble (return true) to let other handlers process
     * - If filter matches: delegate to wrapped handler, then respect $bubble setting
     *
     * This ensures filtered-out records can still be processed by other handlers
     * in the chain, while matched records follow the configured bubble behavior.
     */
    public function handle(LogRecord $record): bool
    {
        if (!$this->shouldHandle($record)) {
            // Record doesn't match our filter - always let it bubble
            // so other handlers in the chain can process it
            return true;
        }

        // Record matches - delegate to wrapped handler
        $this->handler->handle($record);

        // Return our bubble setting (controls whether record continues to next handler)
        return $this->bubble;
    }

    /**
     * {@inheritdoc}
     */
    public function handleBatch(array $records): void
    {
        $filtered = array_filter($records, fn (LogRecord $record) => $this->shouldHandle($record));

        if (!empty($filtered)) {
            $this->handler->handleBatch(array_values($filtered));
        }
    }

    /**
     * {@inheritdoc}
     */
    public function close(): void
    {
        $this->handler->close();
    }

    /**
     * Check if record should be handled
     */
    private function shouldHandle(LogRecord $record): bool
    {
        $levelValue = $record->level->value;

        // Check minimum level
        if ($levelValue < $this->minLevel->value) {
            return false;
        }

        // Check maximum level
        if ($this->maxLevel !== null && $levelValue > $this->maxLevel->value) {
            return false;
        }

        // Check custom filter
        if ($this->filter !== null && !($this->filter)($record)) {
            return false;
        }

        return true;
    }

    /**
     * Get the wrapped handler
     */
    public function getHandler(): HandlerInterface
    {
        return $this->handler;
    }
}
