<?php

declare(strict_types=1);

namespace WPQueue\Contracts;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Queue driver that can list its queues and pending jobs (admin UI, CLI, REST).
 */
interface InspectableQueue
{
    /**
     * Names of queues that currently hold jobs.
     *
     * @return string[]
     */
    public function queues(): array;

    /**
     * Jobs of a queue keyed by job id.
     *
     * @return array<string, array{payload: string, available_at: int, reserved_at: int|null, attempts: int}>
     */
    public function jobs(string $queue): array;
}
