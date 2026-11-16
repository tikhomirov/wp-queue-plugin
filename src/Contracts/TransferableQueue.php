<?php

declare(strict_types=1);

namespace WPQueue\Contracts;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * A storage backend whose waiting jobs can be moved to another backend
 * (used when the active driver is switched in the admin).
 */
interface TransferableQueue
{
    /**
     * Queue names that currently hold jobs.
     *
     * @return list<string>
     */
    public function queues(): array;

    /**
     * Jobs reserved by a worker right now (not stale).
     */
    public function reservedCount(string $queue): int;

    /**
     * Atomically remove and return waiting jobs (ready or delayed, not reserved).
     *
     * @return list<array{job: JobInterface, available_at: int}>
     */
    public function takePending(string $queue, int $limit = 500): array;
}
