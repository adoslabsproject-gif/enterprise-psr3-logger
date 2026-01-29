<?php

declare(strict_types=1);

namespace AdosLabs\EnterprisePSR3Logger;

use Monolog\Handler\HandlerInterface;
use Monolog\Processor\ProcessorInterface;
use Psr\Log\LoggerInterface;

/**
 * Logger Manager
 *
 * Manages multiple named loggers (channels) with shared or independent configurations.
 *
 * USAGE:
 * ```php
 * $manager = new LoggerManager();
 *
 * // Configure default handler for all channels
 * $manager->setDefaultHandler(new StreamHandler('/var/log/app.log'));
 *
 * // Get loggers (created on demand)
 * $appLog = $manager->channel('app');
 * $securityLog = $manager->channel('security');
 * $auditLog = $manager->channel('audit');
 *
 * // Each channel logs independently
 * $appLog->info('Application started');
 * $securityLog->warning('Failed login attempt');
 * $auditLog->info('User created', ['user_id' => 123]);
 * ```
 *
 * CHANNEL INHERITANCE (ADDITIVE):
 * Channels can be hierarchical (e.g., 'app.http', 'app.db').
 * Child channels ACCUMULATE handlers from ALL parent levels.
 *
 * Example:
 * ```php
 * $manager->setChannelHandlers('app', [$fileHandler]);
 * $manager->setChannelHandlers('app.http', [$httpHandler]);
 *
 * // 'app.http.requests' will get BOTH handlers:
 * // - $fileHandler (from 'app')
 * // - $httpHandler (from 'app.http')
 * ```
 *
 * If you want a child channel to use ONLY its own handlers (not inherited),
 * explicitly set handlers for that channel using setChannelHandlers().
 */
class LoggerManager
{
    /** @var array<string, Logger> */
    private array $loggers = [];

    /** @var array<string, array<HandlerInterface>> Channel-specific handlers */
    private array $channelHandlers = [];

    /** @var array<string, array<ProcessorInterface|callable>> Channel-specific processors */
    private array $channelProcessors = [];

    /** @var array<HandlerInterface> Default handlers for all channels */
    private array $defaultHandlers = [];

    /** @var array<ProcessorInterface|callable> Default processors for all channels */
    private array $defaultProcessors = [];

    /** @var array<string, mixed> Global context for all loggers */
    private array $globalContext = [];

    /**
     * PERFORMANCE: Cache for inherited handlers to avoid repeated explode/loop
     * Invalidated when channelHandlers or defaultHandlers change
     * @var array<string, array<HandlerInterface>>
     */
    private array $inheritedHandlersCache = [];

    /**
     * PERFORMANCE: Cache for inherited processors
     * @var array<string, array<ProcessorInterface|callable>>
     */
    private array $inheritedProcessorsCache = [];

    /**
     * Get or create a logger for a channel
     *
     * @param string $channel Channel name
     * @return Logger
     */
    public function channel(string $channel): Logger
    {
        if (!isset($this->loggers[$channel])) {
            $this->loggers[$channel] = $this->createLogger($channel);
        }

        return $this->loggers[$channel];
    }

    /**
     * Alias for channel() - PSR-3 style
     *
     * @param string $channel
     * @return LoggerInterface
     */
    public function get(string $channel): LoggerInterface
    {
        return $this->channel($channel);
    }

    /**
     * Set the default handler for all channels
     *
     * @param HandlerInterface $handler
     * @return self
     */
    public function setDefaultHandler(HandlerInterface $handler): self
    {
        $this->defaultHandlers = [$handler];
        $this->invalidateInheritanceCache();

        return $this;
    }

    /**
     * Add a default handler for all channels
     *
     * @param HandlerInterface $handler
     * @return self
     */
    public function addDefaultHandler(HandlerInterface $handler): self
    {
        $this->defaultHandlers[] = $handler;
        $this->invalidateInheritanceCache();

        return $this;
    }

    /**
     * Add a default processor for all channels
     *
     * @param ProcessorInterface|callable $processor
     * @return self
     */
    public function addDefaultProcessor(ProcessorInterface|callable $processor): self
    {
        $this->defaultProcessors[] = $processor;
        $this->invalidateInheritanceCache();

        return $this;
    }

    /**
     * Set handlers for a specific channel
     *
     * @param string $channel
     * @param array<HandlerInterface> $handlers
     * @return self
     */
    public function setChannelHandlers(string $channel, array $handlers): self
    {
        $this->channelHandlers[$channel] = $handlers;
        $this->invalidateInheritanceCache();

        // Close and reconfigure existing logger if already created
        if (isset($this->loggers[$channel])) {
            // Close old logger to release resources
            $this->loggers[$channel]->close();
            $this->loggers[$channel] = $this->createLogger($channel);
        }

        return $this;
    }

    /**
     * Add a handler to a specific channel
     *
     * @param string $channel
     * @param HandlerInterface $handler
     * @return self
     */
    public function addChannelHandler(string $channel, HandlerInterface $handler): self
    {
        if (!isset($this->channelHandlers[$channel])) {
            $this->channelHandlers[$channel] = [];
        }

        $this->channelHandlers[$channel][] = $handler;
        $this->invalidateInheritanceCache();

        // Update existing logger if already created
        if (isset($this->loggers[$channel])) {
            $this->loggers[$channel]->addHandler($handler);
        }

        return $this;
    }

    /**
     * Add a processor to a specific channel
     *
     * @param string $channel
     * @param ProcessorInterface|callable $processor
     * @return self
     */
    public function addChannelProcessor(string $channel, ProcessorInterface|callable $processor): self
    {
        if (!isset($this->channelProcessors[$channel])) {
            $this->channelProcessors[$channel] = [];
        }

        $this->channelProcessors[$channel][] = $processor;
        $this->invalidateInheritanceCache();

        // Update existing logger if already created
        if (isset($this->loggers[$channel])) {
            $this->loggers[$channel]->addProcessor($processor);
        }

        return $this;
    }

    /**
     * Set global context for all loggers
     *
     * @param array<string, mixed> $context
     * @return self
     */
    public function setGlobalContext(array $context): self
    {
        $this->globalContext = $context;

        // Update existing loggers
        foreach ($this->loggers as $logger) {
            $logger->setGlobalContext($context);
        }

        return $this;
    }

    /**
     * Add to global context
     *
     * @param string $key
     * @param mixed $value
     * @return self
     */
    public function addGlobalContext(string $key, mixed $value): self
    {
        $this->globalContext[$key] = $value;

        // Update existing loggers
        foreach ($this->loggers as $logger) {
            $logger->addGlobalContext($key, $value);
        }

        return $this;
    }

    /**
     * Get all registered channel names
     *
     * @return array<string>
     */
    public function getChannels(): array
    {
        return array_keys($this->loggers);
    }

    /**
     * Check if a channel exists
     *
     * @param string $channel
     * @return bool
     */
    public function hasChannel(string $channel): bool
    {
        return isset($this->loggers[$channel]);
    }

    /**
     * Close all loggers
     */
    public function closeAll(): void
    {
        foreach ($this->loggers as $logger) {
            $logger->close();
        }
    }

    /**
     * Create a new logger for a channel
     */
    private function createLogger(string $channel): Logger
    {
        // Determine handlers: channel-specific or default
        $handlers = $this->channelHandlers[$channel] ?? $this->getInheritedHandlers($channel);
        $processors = $this->channelProcessors[$channel] ?? $this->getInheritedProcessors($channel);

        $logger = new Logger($channel, $handlers, $processors);
        $logger->setGlobalContext($this->globalContext);

        return $logger;
    }

    /**
     * Get handlers for a channel, including inherited from parent channels
     *
     * PERFORMANCE: Results are cached to avoid repeated string parsing
     * Cache is invalidated when handlers are modified
     *
     * @param string $channel
     * @return array<HandlerInterface>
     */
    private function getInheritedHandlers(string $channel): array
    {
        // Check cache first (O(1) lookup)
        if (isset($this->inheritedHandlersCache[$channel])) {
            return $this->inheritedHandlersCache[$channel];
        }

        $handlers = [];

        // Check parent channels (e.g., 'app.http' inherits from 'app')
        $parts = explode('.', $channel);
        $parentChannel = '';

        foreach ($parts as $part) {
            $parentChannel = $parentChannel ? $parentChannel . '.' . $part : $part;

            if (isset($this->channelHandlers[$parentChannel])) {
                $handlers = [...$handlers, ...$this->channelHandlers[$parentChannel]];
            }
        }

        // Use default handlers if no inherited handlers found
        if (empty($handlers)) {
            $handlers = $this->defaultHandlers;
        }

        // Cache result
        $this->inheritedHandlersCache[$channel] = $handlers;

        return $handlers;
    }

    /**
     * Get processors for a channel, including inherited from parent channels
     *
     * PERFORMANCE: Results are cached to avoid repeated string parsing
     * Cache is invalidated when processors are modified
     *
     * @param string $channel
     * @return array<ProcessorInterface|callable>
     */
    private function getInheritedProcessors(string $channel): array
    {
        // Check cache first (O(1) lookup)
        if (isset($this->inheritedProcessorsCache[$channel])) {
            return $this->inheritedProcessorsCache[$channel];
        }

        $processors = $this->defaultProcessors;

        // Check parent channels
        $parts = explode('.', $channel);
        $parentChannel = '';

        foreach ($parts as $part) {
            $parentChannel = $parentChannel ? $parentChannel . '.' . $part : $part;

            if (isset($this->channelProcessors[$parentChannel])) {
                $processors = [...$processors, ...$this->channelProcessors[$parentChannel]];
            }
        }

        // Cache result
        $this->inheritedProcessorsCache[$channel] = $processors;

        return $processors;
    }

    /**
     * Invalidate inheritance cache when configuration changes
     *
     * PERFORMANCE: Full cache clear is simple and safe.
     * Only called on config changes (rare), not on logging (hot path).
     */
    private function invalidateInheritanceCache(): void
    {
        $this->inheritedHandlersCache = [];
        $this->inheritedProcessorsCache = [];
    }
}
