<?php

declare(strict_types=1);

namespace WPQueue;

use WPQueue\Admin\AdminPage;
use WPQueue\Admin\RestApi;
use WPQueue\Contracts\JobInterface;
use WPQueue\Jobs\PendingDispatch;
use WPQueue\Loopback\LoopbackHandler;
use WPQueue\Runtime\RuntimeMode;
use WPQueue\Storage\LogStorage;
use WPQueue\Support\Lock;
use WPQueue\Support\Schema;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Main facade for WP Queue.
 *
 * @method static PendingDispatch dispatch(JobInterface $job)
 * @method static void dispatchSync(JobInterface $job)
 * @method static void dispatchNow(JobInterface $job)
 * @method static PendingChain chain(array $jobs)
 * @method static PendingBatch batch(array $jobs)
 */
final class WPQueue
{
    private static ?self $instance = null;

    private static bool $booted = false;

    private QueueManager $manager;

    private Dispatcher $dispatcher;

    private Scheduler $scheduler;

    private Worker $worker;

    private LogStorage $logs;

    private function __construct()
    {
        $this->manager = new QueueManager();
        $this->dispatcher = new Dispatcher($this->manager);
        $this->scheduler = new Scheduler();
        $this->logs = new LogStorage();
        $this->worker = new Worker($this->manager);
        $this->worker->setLogger($this->logs);
    }

    public static function getInstance(): self
    {
        return self::$instance ??= new self();
    }

    /**
     * Boot the queue system.
     */
    public static function boot(): void
    {
        if (self::$booted) {
            return;
        }

        self::$booted = true;

        $instance = self::getInstance();

        // Allow plugins to register scheduled jobs
        add_action('init', static function () use ($instance): void {
            do_action('wp_queue_schedule', $instance->scheduler);
            $instance->scheduler->register();
        });

        // Register cron handler for processing queues
        add_action('wp_queue_process', static function (string $queue = 'default'): void {
            self::processQueues($queue, 50, 50);
        });

        // Schedule queue processing (cron / loopback mode). In daemon mode an
        // external worker (`wp queue work`) polls the queue, so stale WP-Cron
        // events of the plugin are removed instead.
        add_action('init', static function (): void {
            if (! RuntimeMode::useCron()) {
                self::unscheduleProcessing();

                return;
            }

            if (! wp_next_scheduled('wp_queue_process')) {
                wp_schedule_event(time(), 'min', 'wp_queue_process');
            }
        });

        // Admin UI
        if (is_admin()) {
            new AdminPage();
        }

        // Loopback async handler (must be outside is_admin())
        if (RuntimeMode::useLoopback()) {
            new LoopbackHandler();
        }

        // REST API (must be outside is_admin() for REST requests to work)
        new RestApi();

        // WP-CLI commands
        if (defined('WP_CLI') && WP_CLI) {
            self::registerCliCommands();
        }
    }

    /**
     * Register WP-CLI commands.
     */
    protected static function registerCliCommands(): void
    {
        \WP_CLI::add_command('queue', CLI\QueueCommand::class);
        \WP_CLI::add_command('queue cron', CLI\CronCommand::class);
    }

    /**
     * Plugin activation.
     */
    public static function activate(): void
    {
        self::install();
    }

    /**
     * Plugin deactivation.
     */
    public static function deactivate(): void
    {
        self::uninstall();
    }

    public static function install(): void
    {
        // Schedule queue processing
        if (RuntimeMode::useCron() && ! wp_next_scheduled('wp_queue_process')) {
            wp_schedule_event(time(), 'min', 'wp_queue_process');
        }

        self::createLogsTable();
        self::migrateLogsOption();
        Schema::install();
    }

    public static function uninstall(): void
    {
        // Отключаем крон
        if (RuntimeMode::useCron()) {
            wp_clear_scheduled_hook('wp_queue_process');
        }

        // Удаляем таблицу логов при деактивации
        global $wpdb;

        $table = $wpdb->prefix.'queue_logs';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange
        $wpdb->query($wpdb->prepare('DROP TABLE IF EXISTS %i', $table));
    }

    protected static function createLogsTable(): void
    {
        global $wpdb;

        $table = $wpdb->prefix.'queue_logs';
        $charsetCollate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            job_id varchar(64) NOT NULL,
            job_class varchar(255) NOT NULL,
            queue varchar(64) NOT NULL,
            status varchar(32) NOT NULL,
            message text NULL,
            attempts smallint(5) unsigned NOT NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY status (status),
            KEY queue (queue),
            KEY created_at (created_at)
        ) {$charsetCollate};";

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.SchemaChange
        $wpdb->query($sql);
    }

    protected static function migrateLogsOption(): void
    {
        $optionKey = 'queue_logs';
        $logs = get_option($optionKey, []);

        if (empty($logs) || ! is_array($logs)) {
            return;
        }

        global $wpdb;
        $table = $wpdb->prefix.'queue_logs';

        foreach ($logs as $log) {
            $wpdb->insert(
                $table,
                [
                    'job_id' => isset($log['job_id']) ? (string) $log['job_id'] : '',
                    'job_class' => isset($log['job_class']) ? (string) $log['job_class'] : '',
                    'queue' => isset($log['queue']) ? (string) $log['queue'] : 'default',
                    'status' => isset($log['status']) ? (string) $log['status'] : 'completed',
                    'message' => $log['message'] ?? null,
                    'attempts' => isset($log['attempts']) ? (int) $log['attempts'] : 0,
                    'created_at' => isset($log['timestamp']) ? gmdate('Y-m-d H:i:s', (int) $log['timestamp']) : gmdate('Y-m-d H:i:s'),
                ],
                [
                    '%s',
                    '%s',
                    '%s',
                    '%s',
                    '%s',
                    '%d',
                    '%s',
                ],
            );
        }

        delete_option($optionKey);
    }

    /**
     * Dispatch a job to the queue.
     */
    public static function dispatch(JobInterface $job): PendingDispatch
    {
        return self::getInstance()->dispatcher->dispatch($job);
    }

    /**
     * Dispatch a job synchronously.
     */
    public static function dispatchSync(JobInterface $job): void
    {
        self::getInstance()->dispatcher->dispatchSync($job);
    }

    /**
     * Dispatch a job immediately.
     */
    public static function dispatchNow(JobInterface $job): void
    {
        self::getInstance()->dispatcher->dispatchNow($job);
    }

    /**
     * Chain multiple jobs.
     *
     * @param  JobInterface[]  $jobs
     */
    public static function chain(array $jobs): PendingChain
    {
        return self::getInstance()->dispatcher->chain($jobs);
    }

    /**
     * Batch multiple jobs.
     *
     * @param  JobInterface[]  $jobs
     */
    public static function batch(array $jobs): PendingBatch
    {
        return self::getInstance()->dispatcher->batch($jobs);
    }

    /**
     * Get the scheduler.
     */
    public static function scheduler(): Scheduler
    {
        return self::getInstance()->scheduler;
    }

    /**
     * Get the queue manager.
     */
    public static function manager(): QueueManager
    {
        return self::getInstance()->manager;
    }

    /**
     * Get log storage.
     */
    public static function logs(): LogStorage
    {
        return self::getInstance()->logs;
    }

    /**
     * Get worker instance.
     */
    public static function worker(): Worker
    {
        return self::getInstance()->worker;
    }

    /**
     * Check if a queue is processing.
     */
    public static function isProcessing(string $queue = 'default'): bool
    {
        // One worker lock for all queues: a worker drains every queue.
        return Lock::isHeld(Lock::WORKER);
    }

    /**
     * Process all known queues under the global worker lock.
     *
     * @return int|null Jobs processed, or null when another worker holds the lock.
     */
    public static function processQueues(string $queue = 'default', int $maxJobs = 50, int $maxTime = 50): ?int
    {
        $token = Lock::acquire(Lock::WORKER, $maxTime + 60);

        if ($token === null) {
            error_log('WP Queue: Processing already running, skipped for queue: '.$queue);

            return null;
        }

        try {
            $instance = self::getInstance();
            $instance->worker->setMaxJobs($maxJobs);
            $instance->worker->setMaxTime($maxTime);
            $instance->worker->resetJobsProcessed();

            $queues = $instance->manager->discoverQueues();

            if (! in_array($queue, $queues, true)) {
                array_unshift($queues, $queue);
            }

            foreach ($queues as $queueName) {
                while (! $instance->worker->shouldStop()) {
                    if (! $instance->worker->runNextJob($queueName)) {
                        break;
                    }
                }
            }

            $processed = $instance->worker->getJobsProcessed();

            if ($processed > 0) {
                error_log('WP Queue: Processing completed. Jobs processed: '.$processed);
            }

            return $processed;
        } finally {
            Lock::release(Lock::WORKER, $token);
        }
    }

    /**
     * Remove every scheduled wp_queue_process event (with or without args).
     */
    public static function unscheduleProcessing(): void
    {
        if (! function_exists('_get_cron_array') || ! function_exists('wp_unschedule_hook')) {
            return;
        }

        foreach ((array) _get_cron_array() as $hooks) {
            if (isset($hooks['wp_queue_process'])) {
                wp_unschedule_hook('wp_queue_process');

                return;
            }
        }
    }

    /**
     * Get queue size.
     */
    public static function queueSize(string $queue = 'default'): int
    {
        return self::getInstance()->manager->connection()->size($queue);
    }

    /**
     * Pause a queue.
     */
    public static function pause(string $queue = 'default'): void
    {
        update_site_option('wp_queue_status_'.$queue, 'paused');
    }

    /**
     * Resume a queue.
     */
    public static function resume(string $queue = 'default'): void
    {
        delete_site_option('wp_queue_status_'.$queue);
    }

    /**
     * Check if queue is paused.
     */
    public static function isPaused(string $queue = 'default'): bool
    {
        return get_site_option('wp_queue_status_'.$queue) === 'paused';
    }

    /**
     * Clear all jobs from a queue.
     */
    public static function clear(string $queue = 'default'): int
    {
        return self::getInstance()->manager->connection()->clear($queue);
    }

    /**
     * Cancel a queue (clear and pause).
     */
    public static function cancel(string $queue = 'default'): void
    {
        self::clear($queue);
        self::pause($queue);
    }
}
