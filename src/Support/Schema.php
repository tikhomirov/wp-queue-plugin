<?php

declare(strict_types=1);

namespace WPQueue\Support;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Tables for the database driver and the worker lock.
 *
 * Plain MySQL DDL on purpose: it is understood by MySQL/MariaDB and by the
 * PG4WP rewriter used on PostgreSQL installations.
 */
final class Schema
{
    public const VERSION = 1;

    public const OPTION = 'wp_queue_db_schema';

    private static bool $ready = false;

    public static function jobsTable(): string
    {
        global $wpdb;

        return $wpdb->prefix.'queue_jobs';
    }

    public static function locksTable(): string
    {
        global $wpdb;

        return $wpdb->prefix.'queue_locks';
    }

    /**
     * Create tables once per request when the stored schema version is outdated.
     */
    public static function ensure(): void
    {
        if (self::$ready) {
            return;
        }

        $version = function_exists('get_option') ? (int) get_option(self::OPTION, 0) : 0;

        if ($version < self::VERSION) {
            self::install();
        }

        self::$ready = true;
    }

    public static function install(): void
    {
        global $wpdb;

        if (! isset($wpdb)) {
            return;
        }

        $jobs = self::jobsTable();
        $locks = self::locksTable();
        $charsetCollate = method_exists($wpdb, 'get_charset_collate') ? $wpdb->get_charset_collate() : '';

        // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.SchemaChange
        $wpdb->query("CREATE TABLE IF NOT EXISTS {$jobs} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            job_id varchar(64) NOT NULL,
            queue varchar(64) NOT NULL,
            payload longtext NOT NULL,
            attempts int(11) NOT NULL DEFAULT 0,
            available_at bigint(20) NOT NULL DEFAULT 0,
            reserved_at bigint(20) NULL DEFAULT NULL,
            reserved_by varchar(64) NULL DEFAULT NULL,
            created_at bigint(20) NOT NULL DEFAULT 0,
            PRIMARY KEY  (id),
            UNIQUE KEY job_id (job_id),
            KEY queue_available (queue, available_at)
        ) {$charsetCollate};");

        $wpdb->query("CREATE TABLE IF NOT EXISTS {$locks} (
            name varchar(64) NOT NULL,
            owner varchar(64) NULL DEFAULT NULL,
            expires_at bigint(20) NOT NULL DEFAULT 0,
            PRIMARY KEY  (name)
        ) {$charsetCollate};");
        // phpcs:enable

        self::migrateLegacyOptions();

        if (function_exists('update_option')) {
            update_option(self::OPTION, self::VERSION, false);
        }

        self::$ready = true;
    }

    /**
     * Move jobs stored by 1.2.x in wp_options (wp_queue_jobs_<queue>) into the table.
     */
    public static function migrateLegacyOptions(): void
    {
        global $wpdb;

        if (! function_exists('get_option') || ! function_exists('delete_option')) {
            return;
        }

        $options = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
                'wp_queue_jobs_%',
            ),
        );

        foreach ((array) $options as $optionName) {
            $queue = substr((string) $optionName, strlen('wp_queue_jobs_'));
            $jobs = get_option($optionName, []);

            if (is_array($jobs)) {
                foreach ($jobs as $jobId => $data) {
                    if (! is_array($data) || ! isset($data['payload'])) {
                        continue;
                    }

                    $wpdb->insert(self::jobsTable(), [
                        'job_id' => (string) $jobId,
                        'queue' => $queue,
                        'payload' => base64_encode((string) $data['payload']),
                        'attempts' => (int) ($data['attempts'] ?? 0),
                        'available_at' => (int) ($data['available_at'] ?? time()),
                        'created_at' => time(),
                    ], ['%s', '%s', '%s', '%d', '%d', '%d']);
                }
            }

            delete_option($optionName);
        }
    }

    /**
     * Reset the per-request cache (tests).
     */
    public static function reset(): void
    {
        self::$ready = false;
    }

    /**
     * Treat the schema as installed (tests with a pre-built database).
     */
    public static function markReady(): void
    {
        self::$ready = true;
    }
}
