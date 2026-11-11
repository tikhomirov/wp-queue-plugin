<?php

declare(strict_types=1);

/**
 * Child process for the Redis parallel-worker test: drains the "parallel"
 * queue and prints handled job ids, one per line.
 *
 * Usage: php concurrent-redis-worker.php <host> <port> <prefix>
 */
if (PHP_SAPI !== 'cli') {
    exit;
}

define('ABSPATH', sys_get_temp_dir().'/');

require dirname(__DIR__, 2).'/vendor/autoload.php';

if (($client = getenv('WP_QUEUE_TEST_REDIS_CLIENT')) !== false && $client !== '') {
    define('WP_REDIS_CLIENT', $client);
}

$queue = new WPQueue\Queue\RedisQueue(['host' => $argv[1], 'port' => (int) $argv[2], 'prefix' => $argv[3]]);

while (($job = $queue->pop('parallel')) !== null) {
    $job->handle();
    echo $job->getId(), "\n";
    $queue->delete($job->getId());
}
