<?php

declare(strict_types=1);

namespace WPQueue\Support;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Cross-process lock stored in a database row.
 *
 * Acquisition is a single conditional UPDATE (atomic in MySQL, PostgreSQL and
 * SQLite), so it does not depend on the object cache: transients/options cached
 * in Redis cannot hand the same lock to two workers.
 */
final class Lock
{
    public const WORKER = 'worker';

    /**
     * @return string|null Owner token on success.
     */
    public static function acquire(string $name, int $ttl): ?string
    {
        global $wpdb;

        Schema::ensure();

        $table = Schema::locksTable();
        $token = self::token();
        $now = time();

        $updated = $wpdb->query($wpdb->prepare(
            "UPDATE {$table} SET owner = %s, expires_at = %d WHERE name = %s AND (owner IS NULL OR expires_at < %d)",
            $token,
            $now + $ttl,
            $name,
            $now,
        ));

        if ((int) $updated === 1) {
            return $token;
        }

        $exists = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE name = %s", $name));

        if ($exists > 0) {
            return null;
        }

        // First use of this lock name: the primary key makes concurrent inserts fail for all but one.
        $suppress = $wpdb->suppress_errors(true);
        $inserted = $wpdb->insert($table, [
            'name' => $name,
            'owner' => $token,
            'expires_at' => $now + $ttl,
        ], ['%s', '%s', '%d']);
        $wpdb->suppress_errors($suppress);

        return $inserted ? $token : null;
    }

    /**
     * Extend a held lock. Returns false when the lock was lost.
     */
    public static function refresh(string $name, string $token, int $ttl): bool
    {
        global $wpdb;

        $table = Schema::locksTable();

        $updated = $wpdb->query($wpdb->prepare(
            "UPDATE {$table} SET expires_at = %d WHERE name = %s AND owner = %s",
            time() + $ttl,
            $name,
            $token,
        ));

        return (int) $updated === 1;
    }

    public static function release(string $name, string $token): void
    {
        global $wpdb;

        $table = Schema::locksTable();

        $wpdb->query($wpdb->prepare(
            "UPDATE {$table} SET owner = NULL, expires_at = 0 WHERE name = %s AND owner = %s",
            $name,
            $token,
        ));
    }

    public static function isHeld(string $name): bool
    {
        global $wpdb;

        if (! isset($wpdb)) {
            return false;
        }

        Schema::ensure();

        $table = Schema::locksTable();
        $suppress = $wpdb->suppress_errors(true);
        $held = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE name = %s AND owner IS NOT NULL AND expires_at >= %d",
            $name,
            time(),
        ));
        $wpdb->suppress_errors($suppress);

        return $held > 0;
    }

    private static function token(): string
    {
        return bin2hex(random_bytes(16));
    }
}
