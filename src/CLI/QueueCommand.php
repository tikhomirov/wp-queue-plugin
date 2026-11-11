<?php

declare(strict_types=1);

namespace WPQueue\CLI;

use WP_CLI;
use WP_CLI\Utils;
use WPQueue\Admin\SystemStatus;
use WPQueue\Runtime\Runner;
use WPQueue\Support\DriverSwitcher;
use WPQueue\Support\Lock;
use WPQueue\Support\Settings;
use WPQueue\WPQueue;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Manage WP Queue jobs and cron events.
 *
 * ## EXAMPLES
 *
 *     # Show queue status
 *     $ wp queue status
 *
 *     # Process jobs from default queue
 *     $ wp queue work
 *
 *     # List all cron events
 *     $ wp queue cron list
 */
class QueueCommand
{
    /**
     * Show queue status.
     *
     * ## OPTIONS
     *
     * [--format=<format>]
     * : Output format.
     * ---
     * default: table
     * options:
     *   - table
     *   - json
     *   - csv
     *   - yaml
     * ---
     *
     * ## EXAMPLES
     *
     *     wp queue status
     *     wp queue status --format=json
     *
     * @when after_wp_load
     */
    public function status(array $args, array $assocArgs): void
    {
        $queues = [];
        $manager = WPQueue::manager();

        foreach ($manager->discoverQueues() as $queueName) {
            $jobs = $manager->queuedJobs($queueName);

            $queues[] = [
                'queue' => $queueName,
                'jobs' => count($jobs),
                'status' => WPQueue::isPaused($queueName) ? 'paused' : (WPQueue::isProcessing($queueName) ? 'processing' : 'idle'),
            ];
        }

        if (empty($queues)) {
            $queues[] = [
                'queue' => 'default',
                'jobs' => 0,
                'status' => 'idle',
            ];
        }

        $format = $assocArgs['format'] ?? 'table';
        Utils\format_items($format, $queues, ['queue', 'jobs', 'status']);
    }

    /**
     * Process jobs from a queue.
     *
     * ## OPTIONS
     *
     * [<queue>]
     * : Queue name to process.
     * ---
     * default: default
     * ---
     *
     * [--limit=<limit>]
     * : Maximum number of jobs to process.
     * ---
     * default: 100
     * ---
     *
     * [--memory=<memory>]
     * : Memory limit in MB.
     * ---
     * default: 256
     * ---
     *
     * [--all]
     * : Process every queue that has jobs (the queue argument is processed first).
     *
     * [--max-time=<seconds>]
     * : Stop after this many seconds (0 = no limit).
     * ---
     * default: 0
     * ---
     *
     * [--force]
     * : Process even if another runner (loopback, WP-Cron, sync) is selected.
     *
     * [--daemon]
     * : Run as a daemon worker that continuously polls the queue.
     *   In daemon mode loopback requests and WP-Cron scheduling are skipped
     *   on the current process so the worker does not fight with itself.
     *
     * ## EXAMPLES
     *
     *     wp queue work
     *     wp queue work emails --limit=50
     *     wp queue work imports --memory=512
     *     wp queue work --all --limit=50 --max-time=50
     *     wp queue work --daemon
     *     wp queue work imports --daemon --memory=512
     *
     * @when after_wp_load
     */
    public function work(array $args, array $assocArgs): void
    {
        $queue = $args[0] ?? 'default';

        // Exactly one runner is active. A system cron line left in place after
        // switching to another runner only reports that it is still there.
        if (empty($assocArgs['force']) && ! Runner::cliWorkerAllowed()) {
            Runner::beat(Runner::DAEMON, 0, true);
            WP_CLI::log(sprintf('Active runner is "%s"; skipping (use --force to process anyway).', Runner::active()));

            return;
        }

        if (! empty($assocArgs['daemon'])) {
            $this->runDaemon($queue, $assocArgs);

            return;
        }

        $limit = max(1, (int) ($assocArgs['limit'] ?? 100));
        $maxTime = max(0, (int) ($assocArgs['max-time'] ?? 0));

        // Get default memory limit from WordPress
        $defaultMemory = $this->getDefaultMemoryLimit();
        $memory = (int) ($assocArgs['memory'] ?? $defaultMemory);

        $lockTtl = ($maxTime > 0 ? $maxTime : 3600) + 60;
        $token = Lock::acquire(Lock::WORKER, $lockTtl);

        if ($token === null) {
            Runner::beat(Runner::DAEMON, 0, true);
            WP_CLI::log('Another worker is processing the queues, skipping.');

            return;
        }

        try {
            WP_CLI::log("Processing jobs from queue: {$queue} (memory limit: {$memory}MB, WordPress default: {$defaultMemory}MB)");

            $worker = WPQueue::worker();
            $worker->setMemoryLimit($memory);
            $worker->setMaxJobs($limit);
            $worker->setMaxTime($maxTime);
            $worker->resetJobsProcessed();

            try {
                (new DriverSwitcher(WPQueue::manager()))->drainPrevious();
            } catch (\Throwable $e) {
                WP_CLI::warning('Moving jobs from the previous driver failed: '.$e->getMessage());
            }

            $queues = [$queue];
            if (! empty($assocArgs['all'])) {
                $queues = array_values(array_unique(array_merge($queues, WPQueue::manager()->discoverQueues())));
            }

            foreach ($queues as $queueName) {
                while (! $worker->shouldStop() && $worker->runNextJob($queueName)) {
                    WP_CLI::log("Processed job #{$worker->getJobsProcessed()} ({$queueName})");
                }
            }

            Runner::beat(Runner::DAEMON, $worker->getJobsProcessed());
            WP_CLI::success("Processed {$worker->getJobsProcessed()} jobs from: ".implode(', ', $queues));
        } finally {
            Lock::release(Lock::WORKER, $token);
        }
    }

    /**
     * Run a daemon worker that continuously polls the queue.
     */
    protected function runDaemon(string $queue, array $assocArgs): void
    {
        // Mark this process as the daemon so loopback/cron do not trigger
        // additional workers inside the same process.
        if (! defined('WP_QUEUE_DAEMON')) {
            define('WP_QUEUE_DAEMON', true);
        }

        $memory = (int) ($assocArgs['memory'] ?? 256);

        WP_CLI::log("Starting daemon worker for queue: {$queue} (memory limit: {$memory}MB)");

        $token = Lock::acquire(Lock::WORKER, 120);

        if ($token === null) {
            WP_CLI::error('Another worker is processing the queues.');
        }

        $worker = WPQueue::worker();
        $worker->setMemoryLimit($memory);

        try {
            while (! $worker->shouldStop()) {
                if (! Lock::refresh(Lock::WORKER, $token, 120)) {
                    WP_CLI::warning('Worker lock lost, stopping.');

                    break;
                }

                $lastBeat ??= 0;
                if (time() - $lastBeat >= 30) {
                    Runner::beat(Runner::DAEMON, $worker->getJobsProcessed());
                    $lastBeat = time();
                }

                if (! $worker->runNextJob($queue)) {
                    sleep(3);
                }
            }
        } finally {
            Lock::release(Lock::WORKER, $token);
        }
    }

    /**
     * Clear all jobs from a queue.
     *
     * ## OPTIONS
     *
     * [<queue>]
     * : Queue name to clear.
     * ---
     * default: default
     * ---
     *
     * [--yes]
     * : Skip confirmation.
     *
     * ## EXAMPLES
     *
     *     wp queue clear
     *     wp queue clear emails --yes
     *
     * @when after_wp_load
     */
    public function clear(array $args, array $assocArgs): void
    {
        $queue = $args[0] ?? 'default';

        WP_CLI::confirm("Are you sure you want to clear all jobs from queue '{$queue}'?", $assocArgs);

        $cleared = WPQueue::clear($queue);
        WP_CLI::success("Cleared {$cleared} jobs from queue: {$queue}");
    }

    /**
     * Pause a queue.
     *
     * ## OPTIONS
     *
     * [<queue>]
     * : Queue name to pause.
     * ---
     * default: default
     * ---
     *
     * ## EXAMPLES
     *
     *     wp queue pause
     *     wp queue pause emails
     *
     * @when after_wp_load
     */
    public function pause(array $args, array $assocArgs): void
    {
        $queue = $args[0] ?? 'default';
        WPQueue::pause($queue);
        WP_CLI::success("Queue '{$queue}' paused");
    }

    /**
     * Resume a paused queue.
     *
     * ## OPTIONS
     *
     * [<queue>]
     * : Queue name to resume.
     * ---
     * default: default
     * ---
     *
     * ## EXAMPLES
     *
     *     wp queue resume
     *     wp queue resume emails
     *
     * @when after_wp_load
     */
    public function resume(array $args, array $assocArgs): void
    {
        $queue = $args[0] ?? 'default';
        WPQueue::resume($queue);
        WP_CLI::success("Queue '{$queue}' resumed");
    }

    /**
     * Show failed jobs.
     *
     * ## OPTIONS
     *
     * [--format=<format>]
     * : Output format.
     * ---
     * default: table
     * options:
     *   - table
     *   - json
     *   - csv
     * ---
     *
     * ## EXAMPLES
     *
     *     wp queue failed
     *     wp queue failed --format=json
     *
     * @when after_wp_load
     */
    public function failed(array $args, array $assocArgs): void
    {
        $logs = WPQueue::logs()->failed();

        if (empty($logs)) {
            WP_CLI::log('No failed jobs found.');

            return;
        }

        $items = array_map(fn ($log) => [
            'id' => $log['job_id'] ?? '-',
            'job' => $log['job_class'],
            'queue' => $log['queue'],
            'error' => substr($log['message'] ?? '', 0, 50),
            'time' => wp_date('Y-m-d H:i:s', $log['timestamp']),
        ], $logs);

        $format = $assocArgs['format'] ?? 'table';
        Utils\format_items($format, $items, ['id', 'job', 'queue', 'error', 'time']);
    }

    /**
     * Show system status.
     *
     * ## OPTIONS
     *
     * [--format=<format>]
     * : Output format.
     * ---
     * default: table
     * options:
     *   - table
     *   - json
     *   - yaml
     * ---
     *
     * ## EXAMPLES
     *
     *     wp queue system
     *     wp queue system --format=json
     *
     * @when after_wp_load
     */
    public function system(array $args, array $assocArgs): void
    {
        $status = new SystemStatus();
        $report = $status->getFullReport();
        $health = $status->getHealthStatus();

        $format = $assocArgs['format'] ?? 'table';

        if ($format === 'json') {
            WP_CLI::log(wp_json_encode(['report' => $report, 'health' => $health], JSON_PRETTY_PRINT));

            return;
        }

        $items = [
            ['key' => 'PHP Version', 'value' => $report['php_version']],
            ['key' => 'WordPress Version', 'value' => $report['wp_version']],
            ['key' => 'Memory Limit', 'value' => $report['memory_limit_formatted']],
            ['key' => 'Memory Usage', 'value' => $report['current_memory_formatted'].' ('.$report['memory_percent'].'%)'],
            ['key' => 'Max Execution Time', 'value' => $report['max_execution_time'].'s'],
            ['key' => 'WP-Cron Disabled', 'value' => $report['wp_cron_disabled'] ? 'Yes' : 'No'],
            ['key' => 'Loopback Status', 'value' => $report['loopback']['status']],
            ['key' => 'Timezone', 'value' => $report['timezone']],
            ['key' => 'Health Status', 'value' => $health['status']],
        ];

        Utils\format_items('table', $items, ['key', 'value']);

        if (! empty($health['issues'])) {
            WP_CLI::warning('Issues detected:');
            foreach ($health['issues'] as $issue) {
                WP_CLI::log("  - {$issue}");
            }
        }
    }

    /**
     * Get default memory limit from WordPress configuration.
     * Uses WP_MAX_MEMORY_LIMIT if available, otherwise falls back to WP_MEMORY_LIMIT.
     */
    protected function getDefaultMemoryLimit(): int
    {
        // If WP_MAX_MEMORY_LIMIT is defined, use it (WordPress admin memory limit)
        if (defined('WP_MAX_MEMORY_LIMIT')) {
            $limit = WP_MAX_MEMORY_LIMIT;
        } elseif (defined('WP_MEMORY_LIMIT')) {
            // Fall back to WP_MEMORY_LIMIT
            $limit = WP_MEMORY_LIMIT;
        } else {
            // Last resort: use 256MB
            $limit = '256M';
        }

        // Convert to bytes and then to MB
        if (function_exists('wp_convert_hr_to_bytes')) {
            $bytes = wp_convert_hr_to_bytes($limit);

            return (int) ($bytes / 1024 / 1024);
        }

        // Fallback conversion if wp_convert_hr_to_bytes is not available
        return $this->convertToMB($limit);
    }

    /**
     * Convert human-readable size to MB.
     * Fallback if wp_convert_hr_to_bytes is not available.
     */
    protected function convertToMB(string $value): int
    {
        $value = strtoupper(trim($value));
        $multiplier = 1;

        if (str_ends_with($value, 'G')) {
            $multiplier = 1024 * 1024 * 1024;
            $value = substr($value, 0, -1);
        } elseif (str_ends_with($value, 'M')) {
            $multiplier = 1024 * 1024;
            $value = substr($value, 0, -1);
        } elseif (str_ends_with($value, 'K')) {
            $multiplier = 1024;
            $value = substr($value, 0, -1);
        }

        $bytes = (int) $value * $multiplier;

        return (int) ($bytes / 1024 / 1024);
    }

    /**
     * Show or change the storage driver and the queue runner.
     *
     * ## OPTIONS
     *
     * [--driver=<driver>]
     * : Storage driver: database, redis, memcached. Waiting jobs are moved.
     *
     * [--runner=<runner>]
     * : Queue runner: auto, daemon, loopback, wp_cron, sync.
     *
     * [--store=<where>]
     * : Where to save: option (plugin settings) or wp-config (WP Queue block).
     *
     * ## EXAMPLES
     *
     *     wp queue config
     *     wp queue config --driver=redis
     *     wp queue config --runner=daemon
     *
     * @when after_wp_load
     */
    public function config(array $args, array $assocArgs): void
    {
        $toConfig = match ($assocArgs['store'] ?? null) {
            'wp-config', 'config' => true,
            'option' => false,
            default => null,
        };

        if (isset($assocArgs['driver'])) {
            $result = (new DriverSwitcher(WPQueue::manager()))->switchTo((string) $assocArgs['driver'], $toConfig);
            $this->reportSettingResult($result);
        }

        if (isset($assocArgs['runner'])) {
            $runner = Runner::normalize((string) $assocArgs['runner']);

            if ($runner !== (string) $assocArgs['runner']) {
                WP_CLI::error('Unknown runner. Use: auto, daemon, loopback, wp_cron, sync.');
            }

            $result = Settings::persist('runner', $runner, $toConfig);
            Runner::reset();
            $this->reportSettingResult($result);
        }

        $driver = Settings::driver();
        $runner = Settings::runner();
        $resolved = Runner::resolve();

        WP_CLI::log(sprintf('Driver:  %s (active: %s, source: %s)', $driver['value'], WPQueue::manager()->getDefaultDriver(), $driver['source']));
        WP_CLI::log(sprintf('Runner:  %s (active: %s, source: %s)', $runner['value'], $resolved['runner'], $runner['source']));
        WP_CLI::log('Reason:  '.$resolved['reason']);

        $beat = Runner::heartbeat(Runner::DAEMON);
        WP_CLI::log('Daemon heartbeat: '.($beat === null ? 'never' : gmdate('Y-m-d H:i:s', $beat['at']).' UTC'.($beat['skipped'] ? ' (skipped)' : '')));
    }

    /**
     * @param  array{ok: bool, message: string, snippet?: string}  $result
     */
    private function reportSettingResult(array $result): void
    {
        if (! $result['ok']) {
            if (! empty($result['snippet'])) {
                WP_CLI::log($result['snippet']);
            }

            WP_CLI::error($result['message']);
        }

        WP_CLI::success($result['message']);
    }
}
