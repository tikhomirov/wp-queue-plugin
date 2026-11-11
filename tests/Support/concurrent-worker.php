<?php

declare(strict_types=1);

/**
 * Child process for the parallel-worker test: drains the queue from a shared
 * SQLite file and prints the handled job ids, one per line.
 *
 * Usage: php concurrent-worker.php <sqlite-file>
 */
if (PHP_SAPI !== 'cli') {
    exit;
}

define('ABSPATH', sys_get_temp_dir().'/');
define('ARRAY_A', 'ARRAY_A');

require dirname(__DIR__, 2).'/vendor/autoload.php';

$GLOBALS['wpdb'] = new WPQueue\Tests\Support\SqliteWpdb($argv[1]);

WPQueue\Support\Schema::markReady();

$queue = new WPQueue\Queue\DatabaseQueue();

while (($job = $queue->pop('parallel')) !== null) {
    $job->handle();
    echo $job->getId(), "\n";
    $queue->delete($job->getId());
}
