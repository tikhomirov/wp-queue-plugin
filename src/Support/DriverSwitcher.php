<?php

declare(strict_types=1);

namespace WPQueue\Support;

use WPQueue\Contracts\QueueInterface;
use WPQueue\Contracts\TransferableQueue;
use WPQueue\QueueManager;
use WPQueue\Runtime\Runner;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Switches the storage driver without losing jobs.
 *
 * Waiting jobs (ready and delayed) are moved to the new driver under the global
 * worker lock, so no worker can take a job while it is being moved. Switching is
 * refused while jobs are being executed right now, and for drivers whose jobs
 * cannot be enumerated (Memcached) unless their queues are empty.
 *
 * Jobs pushed by requests that were already running with the old driver are
 * picked up later: the previous driver is remembered and drained by the worker.
 */
final class DriverSwitcher
{
    public const PREVIOUS_OPTION = 'wp_queue_previous_driver';

    public function __construct(private QueueManager $manager) {}

    /**
     * @return array{ok: bool, message: string, moved?: int, snippet?: string}
     */
    public function switchTo(string $driver, ?bool $toConfig = null): array
    {
        if (! in_array($driver, Settings::DRIVERS, true)) {
            return ['ok' => false, 'message' => __('Unknown storage driver.', 'wp-queue')];
        }

        if (Settings::isLocked('driver')) {
            return ['ok' => false, 'message' => __('The storage driver is defined in wp-config.php and cannot be changed here.', 'wp-queue')];
        }

        $current = $this->manager->getDefaultDriver();

        if ($current === $driver && $toConfig === null) {
            return ['ok' => true, 'message' => __('This storage driver is already active.', 'wp-queue'), 'moved' => 0];
        }

        $status = $this->manager->getDriverStatus($driver);

        if ($status['status'] !== QueueManager::STATUS_READY) {
            return ['ok' => false, 'message' => sprintf(
                /* translators: %s: driver status message */
                __('The storage driver is not available: %s', 'wp-queue'),
                $status['message'],
            )];
        }

        $token = Lock::acquire(Lock::WORKER, 300);

        if ($token === null) {
            return ['ok' => false, 'message' => __('A worker is processing jobs right now. Try again in a minute.', 'wp-queue')];
        }

        try {
            $from = $this->manager->connection($current);
            $to = $this->manager->connection($driver);

            $check = $this->checkSource($from);

            if ($check !== true) {
                return ['ok' => false, 'message' => $check];
            }

            $moved = $current !== $driver && $from instanceof TransferableQueue ? self::move($from, $to) : 0;

            $saved = Settings::persist('driver', $driver, $toConfig);

            if (! $saved['ok']) {
                // Put the jobs back where the (unchanged) setting expects them.
                if ($to instanceof TransferableQueue && $moved > 0 && $current !== $driver) {
                    self::move($to, $from);
                }

                return $saved;
            }

            if ($current !== $driver) {
                Options::set(self::PREVIOUS_OPTION, ['driver' => $current, 'until' => time() + 86400]);
            }
            $this->manager->setDefaultDriver($driver);
            Runner::reset();

            return [
                'ok' => true,
                'moved' => $moved,
                'message' => sprintf(
                    /* translators: 1: driver, 2: number of jobs */
                    __('Storage driver switched to %1$s. Jobs moved: %2$d.', 'wp-queue'),
                    $driver,
                    $moved,
                ),
            ];
        } finally {
            Lock::release(Lock::WORKER, $token);
        }
    }

    /**
     * Move leftovers from the previously active driver (called by workers).
     */
    public function drainPrevious(): int
    {
        $previous = Options::get(self::PREVIOUS_OPTION);

        if (! is_array($previous) || empty($previous['driver'])) {
            return 0;
        }

        if ((int) ($previous['until'] ?? 0) < time()) {
            Options::delete(self::PREVIOUS_OPTION);

            return 0;
        }

        $old = (string) $previous['driver'];
        $current = $this->manager->getDefaultDriver();

        if ($old === $current || ! $this->manager->isDriverReady($old)) {
            return 0;
        }

        try {
            $from = $this->manager->connection($old);
        } catch (\Throwable) {
            return 0;
        }

        return $from instanceof TransferableQueue ? self::move($from, $this->manager->connection($current)) : 0;
    }

    private function checkSource(QueueInterface $from): true|string
    {
        if ($from instanceof TransferableQueue) {
            foreach ($from->queues() as $queue) {
                $reserved = $from->reservedCount($queue);

                if ($reserved > 0) {
                    return sprintf(
                        /* translators: 1: number, 2: queue */
                        __('%1$d job(s) in queue "%2$s" are being executed. Try again when they finish.', 'wp-queue'),
                        $reserved,
                        $queue,
                    );
                }
            }

            return true;
        }

        foreach ($this->manager->discoverQueues() as $queue) {
            if ($from->size($queue) > 0) {
                return __('The current storage driver cannot move its jobs. Wait until its queues are empty, then switch.', 'wp-queue');
            }
        }

        return true;
    }

    /**
     * @return int Number of jobs moved.
     */
    public static function move(TransferableQueue $from, QueueInterface $to): int
    {
        $moved = 0;

        foreach ($from->queues() as $queue) {
            do {
                $batch = $from->takePending($queue, 200);

                foreach ($batch as $index => $item) {
                    $job = $item['job'];
                    $job->delay(max(0, $item['available_at'] - time()));

                    try {
                        $to->push($job);
                        $moved++;
                    } catch (\Throwable $e) {
                        // Return this and the remaining jobs to the source.
                        foreach (array_slice($batch, $index) as $rest) {
                            $rest['job']->delay(max(0, $rest['available_at'] - time()));
                            $from instanceof QueueInterface ? $from->push($rest['job']) : null;
                        }

                        throw $e;
                    }
                }
            } while (count($batch) === 200);
        }

        return $moved;
    }
}
