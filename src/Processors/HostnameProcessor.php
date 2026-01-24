<?php

declare(strict_types=1);

namespace Senza1dio\EnterprisePSR3Logger\Processors;

use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

/**
 * Hostname Processor
 *
 * Adds server/host information to log records.
 * Essential for distributed systems and multi-server deployments.
 *
 * ADDED FIELDS (in extra):
 * - hostname: Server hostname
 * - server_ip: Server IP address
 * - php_version: PHP version (optional)
 * - environment: Application environment (optional)
 *
 * USAGE:
 * ```php
 * $logger->addProcessor(new HostnameProcessor());
 *
 * // With environment
 * $logger->addProcessor(new HostnameProcessor(environment: 'production'));
 * ```
 *
 * @package Senza1dio\EnterprisePSR3Logger\Processors
 */
class HostnameProcessor implements ProcessorInterface
{
    private ?string $hostname = null;
    private ?string $serverIp = null;
    private bool $includePhpVersion;
    private ?string $environment;

    /**
     * @param bool $includePhpVersion Include PHP version
     * @param string|null $environment Application environment (production, staging, etc.)
     */
    public function __construct(
        bool $includePhpVersion = false,
        ?string $environment = null
    ) {
        $this->includePhpVersion = $includePhpVersion;
        $this->environment = $environment ?? getenv('APP_ENV') ?: null;
    }

    /**
     * Process log record
     */
    public function __invoke(LogRecord $record): LogRecord
    {
        $extra = $record->extra;

        // Hostname (cached)
        if ($this->hostname === null) {
            $this->hostname = gethostname() ?: 'unknown';
        }
        $extra['hostname'] = $this->hostname;

        // Server IP (cached)
        if ($this->serverIp === null) {
            $this->serverIp = $_SERVER['SERVER_ADDR']
                ?? gethostbyname($this->hostname)
                ?? 'unknown';
        }
        $extra['server_ip'] = $this->serverIp;

        // PHP version
        if ($this->includePhpVersion) {
            $extra['php_version'] = PHP_VERSION;
        }

        // Environment
        if ($this->environment !== null) {
            $extra['environment'] = $this->environment;
        }

        return $record->with(extra: $extra);
    }
}
