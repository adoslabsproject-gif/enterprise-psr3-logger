<?php

declare(strict_types=1);

namespace AdosLabs\EnterprisePSR3Logger\Handlers;

use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Level;
use Monolog\LogRecord;
use PDO;
use PDOException;
use PDOStatement;

/**
 * Security Database Handler
 *
 * Specialized handler for security_log table with attacker identification columns.
 * Extracts IP, user_id, user_email, user_agent, session_id from context and writes
 * them to dedicated columns for fast querying and attack pattern detection.
 *
 * TABLE SCHEMA (PostgreSQL):
 * ```sql
 * CREATE TABLE security_log (
 *     id BIGSERIAL PRIMARY KEY,
 *     channel VARCHAR(255) NOT NULL DEFAULT 'security',
 *     level VARCHAR(20) NOT NULL,
 *     level_value SMALLINT NOT NULL,
 *     message TEXT NOT NULL,
 *     ip_address VARCHAR(45),
 *     user_id BIGINT,
 *     user_email VARCHAR(255),
 *     user_agent TEXT,
 *     session_id VARCHAR(64),
 *     context JSONB,
 *     extra JSONB,
 *     created_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP,
 *     request_id VARCHAR(36)
 * );
 * ```
 *
 * USAGE:
 * ```php
 * $handler = new SecurityDatabaseHandler($pdo);
 * Logger::channel('security')->pushHandler($handler);
 *
 * // Context with attacker info is automatically extracted
 * Logger::channel('security')->warning('Failed login', [
 *     'ip' => '192.168.1.1',
 *     'user_id' => 123,
 *     'email' => 'user@example.com',
 * ]);
 * ```
 */
class SecurityDatabaseHandler extends AbstractProcessingHandler
{
    private PDO $pdo;
    private ?PDOStatement $statement = null;
    private bool $initialized = false;

    /**
     * Context keys to extract for attacker identification
     * Maps context key => database column
     */
    private const ATTACKER_FIELDS = [
        'ip' => 'ip_address',
        'ip_address' => 'ip_address',
        'user_id' => 'user_id',
        'user_email' => 'user_email',
        'email' => 'user_email',
        'user_agent' => 'user_agent',
        'session_id' => 'session_id',
    ];

    public function __construct(
        PDO $pdo,
        Level $level = Level::Debug,
        bool $bubble = true,
    ) {
        parent::__construct($level, $bubble);

        $this->pdo = $pdo;
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }

    /**
     * {@inheritdoc}
     */
    protected function write(LogRecord $record): void
    {
        $this->insertRecord($record);
    }

    /**
     * Insert a single record with attacker identification
     */
    private function insertRecord(LogRecord $record): void
    {
        try {
            if (!$this->initialized) {
                $this->prepareStatement();
            }

            if ($this->statement === null) {
                return;
            }

            // Extract attacker identification from context
            $context = $record->context;
            $extra = $record->extra;

            // Get attacker info from context (with fallbacks)
            $ipAddress = $context['ip'] ?? $context['ip_address'] ?? $extra['ip'] ?? null;
            $userId = $context['user_id'] ?? $extra['user_id'] ?? null;
            $userEmail = $context['email'] ?? $context['user_email'] ?? $extra['email'] ?? null;
            $userAgent = $context['user_agent'] ?? $extra['user_agent'] ?? null;
            $sessionId = $context['session_id'] ?? $extra['session_id'] ?? null;
            $requestId = $extra['request_id'] ?? $context['request_id'] ?? null;

            // Truncate session_id for security (only store first 64 chars)
            if ($sessionId !== null && strlen($sessionId) > 64) {
                $sessionId = substr($sessionId, 0, 64);
            }

            // Remove extracted fields from context to avoid duplication
            $cleanContext = array_diff_key($context, array_flip([
                'ip', 'ip_address', 'user_id', 'email', 'user_email',
                'user_agent', 'session_id', 'request_id',
            ]));

            $success = $this->statement->execute([
                ':channel' => $record->channel,
                ':level' => $record->level->name,
                ':level_value' => $record->level->value,
                ':message' => $record->message,
                ':ip_address' => $ipAddress,
                ':user_id' => $userId,
                ':user_email' => $userEmail,
                ':user_agent' => $userAgent,
                ':session_id' => $sessionId,
                ':context' => json_encode($cleanContext, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ':extra' => json_encode($extra, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ':created_at' => $record->datetime->format('Y-m-d H:i:s.u'),
                ':request_id' => $requestId,
            ]);

            if ($success) {
                $this->statement->closeCursor();
            }
        } catch (PDOException $e) {
            $this->initialized = false;
            $this->statement = null;
            error_log('SecurityDatabaseHandler: Insert failed - ' . $e->getMessage());
        }
    }

    /**
     * Prepare the insert statement
     */
    private function prepareStatement(): void
    {
        $sql = "INSERT INTO security_log
                (channel, level, level_value, message, ip_address, user_id, user_email, user_agent, session_id, context, extra, created_at, request_id)
                VALUES
                (:channel, :level, :level_value, :message, :ip_address, :user_id, :user_email, :user_agent, :session_id, :context, :extra, :created_at, :request_id)";

        $this->statement = $this->pdo->prepare($sql);
        $this->initialized = true;
    }

    /**
     * {@inheritdoc}
     */
    public function close(): void
    {
        $this->statement = null;
        $this->initialized = false;
        parent::close();
    }
}
