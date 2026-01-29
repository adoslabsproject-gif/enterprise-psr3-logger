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
 * BUBBLE SEMANTICS (important for handler chains):
 * - Record MATCHES filter → handled by wrapped handler, then respects $bubble setting
 * - Record DOESN'T MATCH → ALWAYS bubbles (returns true) so other handlers can process
 *
 * This design ensures that when you have multiple FilterHandlers in a chain,
 * each one only handles its matching records, but non-matching records continue
 * to the next handler.
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
 * // With custom filter (e.g., slow queries)
 * $handler = new FilterHandler(
 *     new StreamHandler('/var/log/slow-queries.log'),
 *     filter: fn(LogRecord $record) => ($record->context['duration_ms'] ?? 0) > 1000
 * );
 * ```
 *
 * COMPLETE LOG SEPARATION EXAMPLE:
 * ```php
 * // Error+ goes to error.log (and stops if bubble=false)
 * $logger->addHandler(new FilterHandler(
 *     new StreamHandler('/var/log/error.log'),
 *     minLevel: Level::Error,
 *     bubble: false  // Don't send errors to info.log too
 * ));
 *
 * // Info to Warning goes to info.log
 * $logger->addHandler(new FilterHandler(
 *     new StreamHandler('/var/log/info.log'),
 *     minLevel: Level::Info,
 *     maxLevel: Level::Warning
 * ));
 *
 * // Debug only goes to debug.log
 * $logger->addHandler(new FilterHandler(
 *     new StreamHandler('/var/log/debug.log'),
 *     minLevel: Level::Debug,
 *     maxLevel: Level::Debug
 * ));
 * ```
 */
final class FilterHandler implements HandlerInterface
{
    private readonly HandlerInterface $handler;
    private readonly Level $minLevel;
    private readonly ?Level $maxLevel;

    /** @var (callable(LogRecord): bool)|null Custom filter function */
    private $filter;

    private readonly bool $bubble;

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
