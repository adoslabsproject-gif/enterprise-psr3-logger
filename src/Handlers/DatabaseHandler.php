<?php

declare(strict_types=1);

namespace AdosLabs\EnterprisePSR3Logger\Handlers;

use Monolog\Formatter\FormatterInterface;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Handler\HandlerInterface;
use Monolog\Level;
use Monolog\LogRecord;
use PDO;
use PDOException;
use PDOStatement;

/**
 * Database Handler
 *
 * Writes logs to a database table using PDO.
 * Supports MySQL, PostgreSQL, SQLite.
 *
 * TABLE SCHEMA (MySQL):
 * ```sql
 * CREATE TABLE logs (
 *     id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 *     channel VARCHAR(255) NOT NULL,
 *     level VARCHAR(20) NOT NULL,
 *     level_value SMALLINT UNSIGNED NOT NULL,
 *     message TEXT NOT NULL,
 *     context JSON,
 *     extra JSON,
 *     created_at DATETIME(6) NOT NULL,
 *     request_id VARCHAR(36),
 *     INDEX idx_channel (channel),
 *     INDEX idx_level (level_value),
 *     INDEX idx_created_at (created_at),
 *     INDEX idx_request_id (request_id)
 * ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
 * ```
 *
 * TABLE SCHEMA (PostgreSQL):
 * ```sql
 * CREATE TABLE logs (
 *     id BIGSERIAL PRIMARY KEY,
 *     channel VARCHAR(255) NOT NULL,
 *     level VARCHAR(20) NOT NULL,
 *     level_value SMALLINT NOT NULL,
 *     message TEXT NOT NULL,
 *     context JSONB,
 *     extra JSONB,
 *     created_at TIMESTAMP(6) NOT NULL,
 *     request_id VARCHAR(36)
 * );
 * CREATE INDEX idx_logs_channel ON logs(channel);
 * CREATE INDEX idx_logs_level ON logs(level_value);
 * CREATE INDEX idx_logs_created_at ON logs(created_at);
 * CREATE INDEX idx_logs_request_id ON logs(request_id);
 * ```
 *
 * USAGE:
 * ```php
 * $pdo = new PDO('mysql:host=localhost;dbname=app', 'user', 'pass');
 * $handler = new DatabaseHandler($pdo);
 * $logger->addHandler($handler);
 * ```
 */
class DatabaseHandler extends AbstractProcessingHandler implements HandlerInterface
{
    private PDO $pdo;
    private string $table;
    private ?PDOStatement $statement = null;
    private bool $initialized = false;

    /** @var array<LogRecord> Buffer for batch inserts */
    private array $buffer = [];
    private int $batchSize;
    private bool $useBatching;

    /**
     * @param PDO $pdo PDO connection
     * @param string $table Table name
     * @param Level $level Minimum log level
     * @param bool $bubble Whether to bubble
     * @param int $batchSize Batch size for inserts (0 = no batching)
     */
    public function __construct(
        PDO $pdo,
        string $table = 'logs',
        Level $level = Level::Debug,
        bool $bubble = true,
        int $batchSize = 0,
    ) {
        parent::__construct($level, $bubble);

        // Validate table name (prevent SQL injection)
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $table)) {
            throw new \InvalidArgumentException('Invalid table name');
        }

        $this->pdo = $pdo;
        $this->table = $table;
        $this->batchSize = max(0, $batchSize);
        $this->useBatching = $batchSize > 0;

        // Set PDO to throw exceptions
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }

    /**
     * {@inheritdoc}
     */
    protected function write(LogRecord $record): void
    {
        if ($this->useBatching) {
            $this->buffer[] = $record;

            if (count($this->buffer) >= $this->batchSize) {
                $this->flush();
            }
        } else {
            $this->insertRecord($record);
        }
    }

    /**
     * Flush buffered records to database
     */
    public function flush(): void
    {
        if (empty($this->buffer)) {
            return;
        }

        try {
            $this->pdo->beginTransaction();

            foreach ($this->buffer as $record) {
                $this->insertRecord($record);
            }

            $this->pdo->commit();
        } catch (PDOException $e) {
            $this->pdo->rollBack();
            error_log('DatabaseHandler: Batch insert failed - ' . $e->getMessage());
        }

        $this->buffer = [];
    }

    /**
     * Insert a single record
     *
     * Note: If execute() fails, we reset the statement to ensure clean state
     * for the next attempt. Some PDO drivers may leave statement in
     * inconsistent state after partial failure.
     */
    private function insertRecord(LogRecord $record): void
    {
        try {
            if (!$this->initialized) {
                $this->prepareStatement();
            }

            if ($this->statement === null) {
                return; // prepareStatement failed silently
            }

            $requestId = $record->extra['request_id'] ?? $record->context['request_id'] ?? null;

            $success = $this->statement->execute([
                ':channel' => $record->channel,
                ':level' => $record->level->name,
                ':level_value' => $record->level->value,
                ':message' => $record->message,
                ':context' => json_encode($record->context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ':extra' => json_encode($record->extra, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ':created_at' => $record->datetime->format('Y-m-d H:i:s.u'),
                ':request_id' => $requestId,
            ]);

            // Reset statement after successful execution for clean state
            if ($success) {
                $this->statement->closeCursor();
            }
        } catch (PDOException $e) {
            // Reset initialized flag to allow retry after table creation
            // Also reset statement to avoid inconsistent state issues
            $this->initialized = false;
            $this->statement = null;
            error_log('DatabaseHandler: Insert failed - ' . $e->getMessage());
        }
    }

    /**
     * Prepare the insert statement
     */
    private function prepareStatement(): void
    {
        $sql = "INSERT INTO {$this->table}
                (channel, level, level_value, message, context, extra, created_at, request_id)
                VALUES
                (:channel, :level, :level_value, :message, :context, :extra, :created_at, :request_id)";

        $this->statement = $this->pdo->prepare($sql);
        $this->initialized = true;
    }

    /**
     * {@inheritdoc}
     */
    public function close(): void
    {
        $this->flush();
        $this->statement = null;
        $this->initialized = false;

        parent::close();
    }

    /**
     * Create the logs table if it doesn't exist
     *
     * @param PDO $pdo PDO connection
     * @param string $table Table name
     * @param string $driver Database driver (mysql, pgsql, sqlite)
     */
    public static function createTable(PDO $pdo, string $table = 'logs', ?string $driver = null): void
    {
        $driver ??= $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

        // Validate table name
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $table)) {
            throw new \InvalidArgumentException('Invalid table name');
        }

        $sql = match ($driver) {
            'mysql' => "
                CREATE TABLE IF NOT EXISTS {$table} (
                    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    channel VARCHAR(255) NOT NULL,
                    level VARCHAR(20) NOT NULL,
                    level_value SMALLINT UNSIGNED NOT NULL,
                    message TEXT NOT NULL,
                    context JSON,
                    extra JSON,
                    created_at DATETIME(6) NOT NULL,
                    request_id VARCHAR(36),
                    INDEX idx_{$table}_channel (channel),
                    INDEX idx_{$table}_level (level_value),
                    INDEX idx_{$table}_created_at (created_at),
                    INDEX idx_{$table}_request_id (request_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ",
            'pgsql' => "
                CREATE TABLE IF NOT EXISTS {$table} (
                    id BIGSERIAL PRIMARY KEY,
                    channel VARCHAR(255) NOT NULL,
                    level VARCHAR(20) NOT NULL,
                    level_value SMALLINT NOT NULL,
                    message TEXT NOT NULL,
                    context JSONB,
                    extra JSONB,
                    created_at TIMESTAMP(6) NOT NULL,
                    request_id VARCHAR(36)
                );
                CREATE INDEX IF NOT EXISTS idx_{$table}_channel ON {$table}(channel);
                CREATE INDEX IF NOT EXISTS idx_{$table}_level ON {$table}(level_value);
                CREATE INDEX IF NOT EXISTS idx_{$table}_created_at ON {$table}(created_at);
                CREATE INDEX IF NOT EXISTS idx_{$table}_request_id ON {$table}(request_id);
            ",
            'sqlite' => "
                CREATE TABLE IF NOT EXISTS {$table} (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    channel TEXT NOT NULL,
                    level TEXT NOT NULL,
                    level_value INTEGER NOT NULL,
                    message TEXT NOT NULL,
                    context TEXT,
                    extra TEXT,
                    created_at TEXT NOT NULL,
                    request_id TEXT
                );
                CREATE INDEX IF NOT EXISTS idx_{$table}_channel ON {$table}(channel);
                CREATE INDEX IF NOT EXISTS idx_{$table}_level ON {$table}(level_value);
                CREATE INDEX IF NOT EXISTS idx_{$table}_created_at ON {$table}(created_at);
                CREATE INDEX IF NOT EXISTS idx_{$table}_request_id ON {$table}(request_id);
            ",
            default => throw new \InvalidArgumentException("Unsupported database driver: {$driver}"),
        };

        $pdo->exec($sql);
    }

    /**
     * Query logs from database
     *
     * @param PDO $pdo PDO connection
     * @param array{
     *     table?: string,
     *     channel?: string,
     *     level?: string,
     *     min_level?: int,
     *     from?: string,
     *     to?: string,
     *     request_id?: string,
     *     search?: string,
     *     limit?: int,
     *     offset?: int,
     *     order?: 'asc'|'desc'
     * } $filters Query filters
     * @return array<array<string, mixed>>
     */
    public static function query(PDO $pdo, array $filters = []): array
    {
        $table = $filters['table'] ?? 'logs';

        // Validate table name
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $table)) {
            throw new \InvalidArgumentException('Invalid table name');
        }

        $where = [];
        $params = [];

        if (isset($filters['channel'])) {
            $where[] = 'channel = :channel';
            $params[':channel'] = $filters['channel'];
        }

        if (isset($filters['level'])) {
            $where[] = 'level = :level';
            $params[':level'] = strtoupper($filters['level']);
        }

        if (isset($filters['min_level'])) {
            $where[] = 'level_value >= :min_level';
            $params[':min_level'] = $filters['min_level'];
        }

        if (isset($filters['from'])) {
            $where[] = 'created_at >= :from';
            $params[':from'] = $filters['from'];
        }

        if (isset($filters['to'])) {
            $where[] = 'created_at <= :to';
            $params[':to'] = $filters['to'];
        }

        if (isset($filters['request_id'])) {
            $where[] = 'request_id = :request_id';
            $params[':request_id'] = $filters['request_id'];
        }

        if (isset($filters['search'])) {
            $where[] = 'message LIKE :search';
            $params[':search'] = '%' . $filters['search'] . '%';
        }

        $whereClause = empty($where) ? '' : 'WHERE ' . implode(' AND ', $where);
        $order = ($filters['order'] ?? 'desc') === 'asc' ? 'ASC' : 'DESC';
        $limit = isset($filters['limit']) ? 'LIMIT ' . (int) $filters['limit'] : '';
        $offset = isset($filters['offset']) ? 'OFFSET ' . (int) $filters['offset'] : '';

        $sql = "SELECT * FROM {$table} {$whereClause} ORDER BY created_at {$order} {$limit} {$offset}";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Get the default formatter
     */
    protected function getDefaultFormatter(): FormatterInterface
    {
        return new \AdosLabs\EnterprisePSR3Logger\Formatters\JsonFormatter(appendNewline: false);
    }
}
