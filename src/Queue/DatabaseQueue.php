<?php

declare(strict_types=1);

namespace WPQueue\Queue;

use WPQueue\Contracts\InspectableQueue;
use WPQueue\Contracts\JobInterface;
use WPQueue\Contracts\QueueInterface;
use WPQueue\Support\Schema;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Database driver: one row per job in {prefix}queue_jobs.
 *
 * A job is claimed with a conditional UPDATE (`... WHERE id = ? AND reserved_at IS NULL`),
 * which is atomic in every SQL engine, so concurrent workers can neither take the
 * same job twice nor lose jobs. The object cache (Redis etc.) is not involved.
 */
class DatabaseQueue implements InspectableQueue, QueueInterface
{
    /**
     * Seconds after which a reserved job is considered abandoned (worker crashed).
     */
    public const STALE_THRESHOLD = 300;

    /**
     * Candidates fetched per pop() attempt.
     */
    protected const CLAIM_BATCH = 10;

    protected string $workerId;

    public function __construct()
    {
        $this->workerId = bin2hex(random_bytes(8));
    }

    public function push(JobInterface $job): string
    {
        global $wpdb;

        Schema::ensure();

        $wpdb->insert(Schema::jobsTable(), [
            'job_id' => $job->getId(),
            'queue' => $job->getQueue() ?: 'default',
            'payload' => $this->encode($job),
            'attempts' => $job->getAttempts(),
            'available_at' => time() + $job->getDelay(),
            'created_at' => time(),
        ], ['%s', '%s', '%s', '%d', '%d', '%d']);

        return $job->getId();
    }

    public function later(int $delay, JobInterface $job): string
    {
        $job->delay($delay);

        return $this->push($job);
    }

    public function pop(string $queue = 'default'): ?JobInterface
    {
        global $wpdb;

        Schema::ensure();

        $table = Schema::jobsTable();
        $now = time();
        $staleBefore = $now - self::STALE_THRESHOLD;

        $candidates = $wpdb->get_results($wpdb->prepare(
            "SELECT id, job_id, payload, reserved_at FROM {$table} WHERE queue = %s AND available_at <= %d AND (reserved_at IS NULL OR reserved_at < %d) ORDER BY available_at ASC, id ASC LIMIT %d",
            $queue,
            $now,
            $staleBefore,
            self::CLAIM_BATCH,
        ), ARRAY_A);

        foreach ((array) $candidates as $row) {
            if (! $this->claim((int) $row['id'], $staleBefore)) {
                // Another worker was faster.
                continue;
            }

            if ($row['reserved_at'] !== null) {
                error_log(sprintf('WP Queue: Releasing stale job %s', $row['job_id']));
            }

            $job = $this->decode((string) $row['payload']);

            if ($job instanceof JobInterface) {
                return $job;
            }

            error_log(sprintf('WP Queue: Failed to unserialize job %s, removing it', $row['job_id']));
            $wpdb->delete($table, ['id' => (int) $row['id']], ['%d']);
        }

        return null;
    }

    /**
     * Atomically reserve one row for this worker.
     */
    protected function claim(int $rowId, int $staleBefore): bool
    {
        global $wpdb;

        $table = Schema::jobsTable();

        $updated = $wpdb->query($wpdb->prepare(
            "UPDATE {$table} SET reserved_at = %d, reserved_by = %s WHERE id = %d AND (reserved_at IS NULL OR reserved_at < %d)",
            time(),
            $this->workerId,
            $rowId,
            $staleBefore,
        ));

        return (int) $updated === 1;
    }

    public function delete(string $jobId): bool
    {
        global $wpdb;

        Schema::ensure();

        return (bool) $wpdb->delete(Schema::jobsTable(), ['job_id' => $jobId], ['%s']);
    }

    public function release(JobInterface $job, int $delay = 0): void
    {
        global $wpdb;

        Schema::ensure();

        $table = Schema::jobsTable();

        $wpdb->query($wpdb->prepare(
            "UPDATE {$table} SET reserved_at = NULL, reserved_by = NULL, available_at = %d, payload = %s, attempts = %d WHERE job_id = %s",
            time() + $delay,
            $this->encode($job),
            $job->getAttempts(),
            $job->getId(),
        ));
    }

    public function size(string $queue = 'default'): int
    {
        global $wpdb;

        Schema::ensure();

        $table = Schema::jobsTable();

        return (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE queue = %s", $queue));
    }

    public function clear(string $queue = 'default'): int
    {
        global $wpdb;

        Schema::ensure();

        return (int) $wpdb->delete(Schema::jobsTable(), ['queue' => $queue], ['%s']);
    }

    public function isEmpty(string $queue = 'default'): bool
    {
        return $this->size($queue) === 0;
    }

    public function queues(): array
    {
        global $wpdb;

        if (! isset($wpdb)) {
            return [];
        }

        Schema::ensure();

        $table = Schema::jobsTable();

        return array_values(array_map('strval', (array) $wpdb->get_col("SELECT DISTINCT queue FROM {$table}")));
    }

    public function jobs(string $queue): array
    {
        global $wpdb;

        Schema::ensure();

        $table = Schema::jobsTable();
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT job_id, payload, available_at, reserved_at, attempts FROM {$table} WHERE queue = %s ORDER BY available_at ASC, id ASC",
            $queue,
        ), ARRAY_A);

        $jobs = [];
        foreach ((array) $rows as $row) {
            $jobs[(string) $row['job_id']] = [
                'payload' => (string) base64_decode((string) $row['payload'], true),
                'available_at' => (int) $row['available_at'],
                'reserved_at' => $row['reserved_at'] === null ? null : (int) $row['reserved_at'],
                'attempts' => (int) $row['attempts'],
            ];
        }

        return $jobs;
    }

    /**
     * Serialized PHP objects contain NUL bytes (private/protected properties),
     * which PostgreSQL text columns reject, so payloads are stored base64-encoded.
     */
    protected function encode(JobInterface $job): string
    {
        return base64_encode(serialize($job));
    }

    protected function decode(string $payload): mixed
    {
        $raw = base64_decode($payload, true);

        if ($raw === false) {
            return null;
        }

        try {
            return unserialize($raw);
        } catch (\Throwable) {
            return null;
        }
    }
}
