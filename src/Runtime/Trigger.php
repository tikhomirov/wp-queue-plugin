<?php

declare(strict_types=1);

namespace WPQueue\Runtime;

use WPQueue\Loopback\LoopbackDispatcher;
use WPQueue\Support\Settings;
use WPQueue\WPQueue;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Starts processing after a job was dispatched — only through the active runner.
 */
final class Trigger
{
    private static bool $cronShutdownRegistered = false;

    private static bool $syncShutdownRegistered = false;

    private static ?string $syncQueue = null;

    /** @var array<string, int> dispatches per queue in this request */
    private static array $loopbackQueues = [];

    public static function afterDispatch(string $queue): void
    {
        switch (Runner::active()) {
            case Runner::LOOPBACK:
                self::loopback($queue);
                break;

            case Runner::WP_CRON:
                self::wpCron($queue);
                break;

            case Runner::SYNC:
                self::sync($queue);
                break;

            default:
                // Daemon / system cron polls the queue itself.
                break;
        }
    }

    /**
     * Start a worker right away; if more jobs follow in the same request,
     * poke it once more at shutdown, after the last push, so a worker that
     * started early does not leave the rest waiting for the next dispatch.
     */
    private static function loopback(string $queue): void
    {
        $first = self::$loopbackQueues === [];
        self::$loopbackQueues[$queue] = (self::$loopbackQueues[$queue] ?? 0) + 1;

        LoopbackDispatcher::spawn($queue);

        if (! $first) {
            return;
        }

        register_shutdown_function(static function (): void {
            foreach (self::$loopbackQueues as $name => $count) {
                if ($count > 1) {
                    LoopbackDispatcher::forget($name);
                    LoopbackDispatcher::spawn($name);
                }
            }
        });
    }

    private static function wpCron(string $queue): void
    {
        if (! function_exists('wp_schedule_single_event') || ! function_exists('wp_next_scheduled')) {
            return;
        }

        if (wp_next_scheduled('wp_queue_process', [$queue]) === false) {
            wp_schedule_single_event(time(), 'wp_queue_process', [$queue]);
        }

        // With DISABLE_WP_CRON the site runs cron from the system scheduler:
        // spawning wp-cron.php here would execute every due hook, not only ours.
        // WordPress never spawns cron from WP-CLI: the non-blocking request may be
        // dropped when the process exits and its lock would hold WP-Cron for a minute.
        if (Settings::constant('DISABLE_WP_CRON') || Settings::constant('WP_CLI') || ! function_exists('spawn_cron') || self::$cronShutdownRegistered) {
            return;
        }

        self::$cronShutdownRegistered = true;

        register_shutdown_function(static function (): void {
            if (! self::$cronShutdownRegistered) {
                return; // cancelled by reset()
            }

            try {
                spawn_cron();
            } catch (\Throwable $e) {
                error_log('WP Queue: spawn_cron failed: '.$e->getMessage());
            }
        });
    }

    private static function sync(string $queue): void
    {
        self::$syncQueue ??= $queue;

        if (self::$syncShutdownRegistered) {
            return;
        }

        self::$syncShutdownRegistered = true;

        register_shutdown_function(static function (): void {
            if (! self::$syncShutdownRegistered) {
                return; // cancelled by reset()
            }

            WPQueue::processQueues(self::$syncQueue ?? 'default', 50, 25, Runner::SYNC);
        });
    }

    /**
     * Pending shutdown work registered in this request (for tests/diagnostics).
     *
     * @return array{spawn_cron: bool, sync: bool}
     */
    public static function state(): array
    {
        return ['spawn_cron' => self::$cronShutdownRegistered, 'sync' => self::$syncShutdownRegistered];
    }

    public static function reset(): void
    {
        self::$cronShutdownRegistered = false;
        self::$syncShutdownRegistered = false;
        self::$syncQueue = null;
        self::$loopbackQueues = [];
    }
}
