<?php

declare(strict_types=1);

namespace WPQueue\Runtime;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Backward-compatible facade over Runner (WP Queue <= 1.3).
 */
final class RuntimeMode
{
    public const MODE_CRON_LOOPBACK = 'cron_loopback';

    public const MODE_DAEMON = 'daemon';

    public const MODE_AUTO = 'auto';

    public static function get(): string
    {
        return match (Runner::active()) {
            Runner::DAEMON => self::MODE_DAEMON,
            default => self::MODE_CRON_LOOPBACK,
        };
    }

    public static function useLoopback(): bool
    {
        return Runner::is(Runner::LOOPBACK);
    }

    /**
     * Whether WP-Cron scheduling should be used.
     */
    public static function useCron(): bool
    {
        return Runner::is(Runner::WP_CRON);
    }

    public static function resolveMode(): string
    {
        return self::get();
    }

    public static function isDaemonProcess(): bool
    {
        if (defined('WP_QUEUE_DAEMON') && WP_QUEUE_DAEMON) {
            return true;
        }

        return getenv('WP_QUEUE_DAEMON') === '1';
    }

    public static function resolveAuto(): string
    {
        return self::get();
    }

    public static function isValid(string $mode): bool
    {
        return in_array($mode, [self::MODE_CRON_LOOPBACK, self::MODE_DAEMON, self::MODE_AUTO], true);
    }
}
