<?php

declare(strict_types=1);

namespace WPQueue\Tests\Support;

use PDO;
use PDOException;

/**
 * Minimal real-SQL $wpdb for unit tests (SQLite via PDO).
 *
 * Only what the queue driver and the lock need. CREATE TABLE statements of the
 * plugin (MySQL DDL) are ignored: equivalent SQLite tables are created here.
 */
final class SqliteWpdb
{
    public string $prefix = 'wp_';

    public string $options = 'wp_options';

    public string $last_error = '';

    public int $rows_affected = 0;

    public int $insert_id = 0;

    private bool $suppress = false;

    private PDO $pdo;

    public function __construct(string $path = ':memory:')
    {
        $this->pdo = new PDO('sqlite:'.$path, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_TIMEOUT => 30,
        ]);
        $this->pdo->exec('PRAGMA busy_timeout = 30000');

        if ($path !== ':memory:') {
            $this->pdo->exec('PRAGMA journal_mode = WAL');
        }

        $this->pdo->exec('CREATE TABLE IF NOT EXISTS wp_queue_jobs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            job_id TEXT NOT NULL UNIQUE,
            queue TEXT NOT NULL,
            payload TEXT NOT NULL,
            attempts INTEGER NOT NULL DEFAULT 0,
            available_at INTEGER NOT NULL DEFAULT 0,
            reserved_at INTEGER NULL,
            reserved_by TEXT NULL,
            created_at INTEGER NOT NULL DEFAULT 0
        )');
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS wp_queue_locks (
            name TEXT PRIMARY KEY,
            owner TEXT NULL,
            expires_at INTEGER NOT NULL DEFAULT 0
        )');
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS wp_options (
            option_name TEXT PRIMARY KEY,
            option_value TEXT
        )');
    }

    public function get_charset_collate(): string
    {
        return '';
    }

    public function suppress_errors(bool $suppress = true): bool
    {
        $previous = $this->suppress;
        $this->suppress = $suppress;

        return $previous;
    }

    public function prepare(string $query, mixed ...$args): string
    {
        if (count($args) === 1 && is_array($args[0])) {
            $args = $args[0];
        }

        $index = 0;

        return (string) preg_replace_callback('/%([sdif])/', function (array $m) use (&$index, $args): string {
            $value = $args[$index++] ?? null;

            return match ($m[1]) {
                'd' => (string) (int) $value,
                'f' => (string) (float) $value,
                'i' => '"'.str_replace('"', '', (string) $value).'"',
                default => $this->pdo->quote((string) $value),
            };
        }, $query);
    }

    public function query(string $sql): int|bool
    {
        if (preg_match('/^\s*CREATE TABLE/i', $sql)) {
            return true;
        }

        try {
            $this->rows_affected = (int) $this->pdo->exec($sql);

            return $this->rows_affected;
        } catch (PDOException $e) {
            return $this->fail($e);
        }
    }

    public function insert(string $table, array $data, array $format = []): int|false
    {
        $columns = implode(', ', array_keys($data));
        $placeholders = implode(', ', array_fill(0, count($data), '?'));

        try {
            $statement = $this->pdo->prepare("INSERT INTO {$table} ({$columns}) VALUES ({$placeholders})");
            $statement->execute(array_values($data));
            $this->insert_id = (int) $this->pdo->lastInsertId();

            return $this->rows_affected = $statement->rowCount();
        } catch (PDOException $e) {
            return $this->fail($e);
        }
    }

    public function delete(string $table, array $where, array $format = []): int|false
    {
        $conditions = implode(' AND ', array_map(static fn ($c) => "{$c} = ?", array_keys($where)));

        try {
            $statement = $this->pdo->prepare("DELETE FROM {$table} WHERE {$conditions}");
            $statement->execute(array_values($where));

            return $this->rows_affected = $statement->rowCount();
        } catch (PDOException $e) {
            return $this->fail($e);
        }
    }

    public function get_results(string $query, string $output = 'OBJECT'): array
    {
        try {
            $rows = $this->pdo->query($query)->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            $this->fail($e);

            return [];
        }

        return $output === 'ARRAY_A' ? $rows : array_map(static fn ($r) => (object) $r, $rows);
    }

    public function get_col(string $query): array
    {
        try {
            return $this->pdo->query($query)->fetchAll(PDO::FETCH_COLUMN);
        } catch (PDOException $e) {
            $this->fail($e);

            return [];
        }
    }

    public function get_var(string $query): mixed
    {
        try {
            $value = $this->pdo->query($query)->fetchColumn();

            return $value === false ? null : $value;
        } catch (PDOException $e) {
            $this->fail($e);

            return null;
        }
    }

    private function fail(PDOException $e): false
    {
        $this->last_error = $e->getMessage();

        return false;
    }
}
