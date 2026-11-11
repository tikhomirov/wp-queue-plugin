<?php

declare(strict_types=1);

namespace WPQueue\Runtime;

use WPQueue\Support\Options;
use WPQueue\Support\Settings;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Queue runner (how queued jobs get executed). Exactly one runner is active:
 *
 * - daemon   — system cron or a long-running `wp queue work` process;
 * - loopback — non-blocking request to admin-ajax.php right after dispatch
 *              (like Action Scheduler's async runner);
 * - wp_cron  — WP-Cron event every minute (+ spawn_cron() after dispatch);
 * - sync     — jobs run at the end of the request that dispatched them.
 *
 * In "auto" the fastest available runner is used, in this order.
 */
final class Runner
{
    public const AUTO = 'auto';

    public const DAEMON = 'daemon';

    public const LOOPBACK = 'loopback';

    public const WP_CRON = 'wp_cron';

    public const SYNC = 'sync';

    public const HEARTBEAT_OPTION = 'wp_queue_runner_heartbeat';

    public const LOOPBACK_CHECK_TRANSIENT = 'wp_queue_loopback_check';

    /**
     * A daemon/system cron is considered alive if it ran within this window.
     */
    public const HEARTBEAT_TTL = 600;

    /**
     * @var array{runner: string, configured: string, auto: bool, reason: string}|null
     */
    private static ?array $resolved = null;

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return [self::DAEMON, self::LOOPBACK, self::WP_CRON, self::SYNC];
    }

    /**
     * Map legacy WP_QUEUE_RUNTIME_MODE values.
     */
    public static function normalize(string $value): string
    {
        return match ($value) {
            'cron_loopback' => self::LOOPBACK,
            'cron', 'wpcron', 'wp-cron' => self::WP_CRON,
            'system_cron', 'cli' => self::DAEMON,
            self::DAEMON, self::LOOPBACK, self::WP_CRON, self::SYNC, self::AUTO => $value,
            default => self::AUTO,
        };
    }

    public static function active(): string
    {
        return self::resolve()['runner'];
    }

    public static function is(string $runner): bool
    {
        return self::active() === $runner;
    }

    /**
     * @return array{runner: string, configured: string, auto: bool, reason: string}
     */
    public static function resolve(): array
    {
        if (self::$resolved !== null) {
            return self::$resolved;
        }

        $configured = Settings::runner()['value'];

        if ($configured !== self::AUTO) {
            $availability = self::availability($configured);

            // A runner disabled by configuration cannot run at all: fall back
            // to auto so the queue is still processed by exactly one runner.
            if ($availability['available'] || ! $availability['hard']) {
                return self::$resolved = [
                    'runner' => $configured,
                    'configured' => $configured,
                    'auto' => false,
                    'reason' => $availability['available']
                        ? __('Selected manually.', 'wp-queue')
                        : sprintf(
                            /* translators: %s: reason */
                            __('Selected manually, but: %s', 'wp-queue'),
                            $availability['reason'],
                        ),
                ];
            }
        }

        foreach (self::all() as $runner) {
            $availability = self::availability($runner);

            if ($availability['available']) {
                return self::$resolved = [
                    'runner' => $runner,
                    'configured' => $configured,
                    'auto' => true,
                    'reason' => $availability['reason'],
                ];
            }
        }

        return self::$resolved = [
            'runner' => self::SYNC,
            'configured' => $configured,
            'auto' => true,
            'reason' => __('No other runner is available.', 'wp-queue'),
        ];
    }

    /**
     * @return array{available: bool, hard: bool, reason: string}
     */
    public static function availability(string $runner): array
    {
        switch ($runner) {
            case self::DAEMON:
                $beat = self::heartbeat(self::DAEMON);

                if ($beat !== null && $beat['at'] >= time() - self::HEARTBEAT_TTL) {
                    return [
                        'available' => true,
                        'hard' => false,
                        /* translators: %s: human time diff */
                        'reason' => sprintf(__('Daemon / system cron is alive: last run %s ago.', 'wp-queue'), self::ago($beat['at'])),
                    ];
                }

                return [
                    'available' => false,
                    'hard' => false,
                    'reason' => $beat === null
                        ? __('No daemon / system cron heartbeat yet.', 'wp-queue')
                        /* translators: %s: human time diff */
                        : sprintf(__('Daemon / system cron heartbeat is stale (%s ago).', 'wp-queue'), self::ago($beat['at'])),
                ];

            case self::LOOPBACK:
                $constant = Settings::constant('WP_QUEUE_LOOPBACK');

                if ($constant !== null && ! $constant) {
                    return ['available' => false, 'hard' => true, 'reason' => __('Loopback is disabled by WP_QUEUE_LOOPBACK in wp-config.php.', 'wp-queue')];
                }

                $check = Options::transient(self::LOOPBACK_CHECK_TRANSIENT);

                if (is_array($check) && ($check['status'] ?? '') === 'error') {
                    return [
                        'available' => false,
                        'hard' => false,
                        /* translators: %s: error message */
                        'reason' => sprintf(__('Loopback request failed: %s', 'wp-queue'), (string) ($check['message'] ?? '')),
                    ];
                }

                return ['available' => true, 'hard' => false, 'reason' => __('Loopback requests to admin-ajax.php work.', 'wp-queue')];

            case self::WP_CRON:
                if (Settings::constant('DISABLE_WP_CRON')) {
                    return ['available' => false, 'hard' => false, 'reason' => __('WP-Cron is disabled (DISABLE_WP_CRON); it runs only if a system cron calls wp-cron.php.', 'wp-queue')];
                }

                return ['available' => true, 'hard' => false, 'reason' => __('WP-Cron runs on site visits.', 'wp-queue')];

            case self::SYNC:
                return ['available' => true, 'hard' => false, 'reason' => __('Jobs run at the end of the request that dispatched them.', 'wp-queue')];
        }

        return ['available' => false, 'hard' => true, 'reason' => __('Unknown runner.', 'wp-queue')];
    }

    /**
     * Record that a runner processed the queues (or that the daemon is present).
     */
    public static function beat(string $runner, int $processed = 0, bool $skipped = false): void
    {
        $beats = Options::get(self::HEARTBEAT_OPTION, []);
        $beats = is_array($beats) ? $beats : [];
        $beats[$runner] = ['at' => time(), 'processed' => $processed, 'skipped' => $skipped];

        Options::set(self::HEARTBEAT_OPTION, $beats);
    }

    /**
     * @return array{at: int, processed: int, skipped: bool}|null
     */
    public static function heartbeat(string $runner): ?array
    {
        $beats = Options::get(self::HEARTBEAT_OPTION, []);
        $beat = is_array($beats) ? ($beats[$runner] ?? null) : null;

        if (! is_array($beat) || ! isset($beat['at'])) {
            return null;
        }

        return [
            'at' => (int) $beat['at'],
            'processed' => (int) ($beat['processed'] ?? 0),
            'skipped' => (bool) ($beat['skipped'] ?? false),
        ];
    }

    /**
     * Whether `wp queue work` may process jobs: the daemon is the active runner,
     * or the runner is "auto" (a running worker makes the daemon available).
     */
    public static function cliWorkerAllowed(): bool
    {
        $configured = Settings::runner()['value'];

        return $configured === self::AUTO || $configured === self::DAEMON || self::active() === self::DAEMON;
    }

    public static function label(string $runner): string
    {
        return match ($runner) {
            self::AUTO => __('Auto', 'wp-queue'),
            self::DAEMON => __('Daemon / system cron', 'wp-queue'),
            self::LOOPBACK => __('Loopback (admin-ajax)', 'wp-queue'),
            self::WP_CRON => __('WP-Cron', 'wp-queue'),
            self::SYNC => __('Synchronous (in request)', 'wp-queue'),
            default => $runner,
        };
    }

    public static function hint(string $runner): string
    {
        return match ($runner) {
            self::AUTO => __('Uses the fastest available runner: daemon / system cron if its heartbeat is fresh, then loopback, WP-Cron, synchronous.', 'wp-queue'),
            self::DAEMON => __('Fastest and most reliable. A system cron runs `wp queue work` every minute (or a daemon runs continuously). WP-Cron and loopback are not used.', 'wp-queue'),
            self::LOOPBACK => __('After a job is dispatched, a non-blocking request to admin-ajax.php starts a worker, like Action Scheduler. Good for shared hosting. Delayed jobs run on the next dispatch.', 'wp-queue'),
            self::WP_CRON => __('WP-Cron checks the queue every minute; needs site traffic or a system cron calling wp-cron.php.', 'wp-queue'),
            self::SYNC => __('Jobs run at the end of the request that dispatched them. No background processing; slows that request down.', 'wp-queue'),
            default => '',
        };
    }

    public static function reset(): void
    {
        self::$resolved = null;
    }

    public static function ago(int $timestamp): string
    {
        $diff = max(0, time() - $timestamp);

        return match (true) {
            /* translators: %d: seconds */
            $diff < 120 => sprintf(__('%d s', 'wp-queue'), $diff),
            /* translators: %d: minutes */
            $diff < 7200 => sprintf(__('%d min', 'wp-queue'), intdiv($diff, 60)),
            /* translators: %d: hours */
            default => sprintf(__('%d h', 'wp-queue'), intdiv($diff, 3600)),
        };
    }
}
