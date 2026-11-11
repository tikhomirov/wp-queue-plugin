<?php

declare(strict_types=1);

use WPQueue\Queue\Redis\RedisClientFactory;
use WPQueue\Queue\RedisQueue;
use WPQueue\Tests\Fixtures\ConcurrencyJob;

/**
 * Needs a Redis server: WP_QUEUE_TEST_REDIS_HOST / WP_QUEUE_TEST_REDIS_PORT
 * (CI starts one). Uses phpredis if loaded, otherwise Predis.
 */
function redisTestConfig(): ?array
{
    $client = getenv('WP_QUEUE_TEST_REDIS_CLIENT');

    if (is_string($client) && $client !== '' && ! defined('WP_REDIS_CLIENT')) {
        define('WP_REDIS_CLIENT', $client);
    }

    $host = getenv('WP_QUEUE_TEST_REDIS_HOST');

    if ($host === false || $host === '' || ! RedisClientFactory::isAvailable()) {
        return null;
    }

    return [
        'host' => $host,
        'port' => (int) (getenv('WP_QUEUE_TEST_REDIS_PORT') ?: 6379),
        'prefix' => 'wpqtest:'.bin2hex(random_bytes(4)).':',
    ];
}

function redisTestQueue(array $config): RedisQueue
{
    return new class($config) extends RedisQueue
    {
        public function wipe(): void
        {
            foreach (['parallel', 'default'] as $queue) {
                $this->clear($queue);
            }

            $this->connection()->del(['queues', 'index']);
        }

        public function client(): \WPQueue\Queue\Redis\RedisClientInterface
        {
            return $this->connection();
        }
    };
}

beforeEach(function (): void {
    $this->redisConfig = redisTestConfig();

    if ($this->redisConfig === null) {
        $this->markTestSkipped('Redis server is not configured (WP_QUEUE_TEST_REDIS_HOST).');
    }

    $this->queue = redisTestQueue($this->redisConfig);
});

afterEach(function (): void {
    if (isset($this->queue)) {
        $this->queue->wipe();
    }
});

describe('RedisQueue', function (): void {
    it('pops each job once and removes it on delete', function (): void {
        $job = new ConcurrencyJob(1);
        $this->queue->push($job);

        $popped = $this->queue->pop('parallel');

        expect($popped?->getId())->toBe($job->getId())
            ->and($this->queue->pop('parallel'))->toBeNull()
            ->and($this->queue->size('parallel'))->toBe(1)
            ->and($this->queue->reservedCount('parallel'))->toBe(1);

        expect($this->queue->delete($job->getId()))->toBeTrue()
            ->and($this->queue->size('parallel'))->toBe(0);
    });

    it('keeps delayed jobs until they are due and releases with a delay', function (): void {
        $job = (new ConcurrencyJob(2))->delay(60);
        $this->queue->push($job);

        expect($this->queue->pop('parallel'))->toBeNull()
            ->and($this->queue->size('parallel'))->toBe(1);

        $ready = new ConcurrencyJob(3);
        $this->queue->push($ready);
        $popped = $this->queue->pop('parallel');
        $this->queue->release($popped, 60);

        expect($this->queue->pop('parallel'))->toBeNull()
            ->and($this->queue->stats('parallel')['delayed'])->toBe(2);
    });

    it('re-queues a job abandoned by a crashed worker', function (): void {
        $job = new ConcurrencyJob(4);
        $this->queue->push($job);
        $this->queue->pop('parallel');

        // Pretend the reservation is older than the stale threshold.
        $this->queue->client()->zAdd('q:parallel:reserved', (float) (time() - RedisQueue::STALE_THRESHOLD - 5), $job->getId());

        expect($this->queue->pop('parallel')?->getId())->toBe($job->getId());
    });

    it('lists queues and jobs and hands waiting jobs over for a driver switch', function (): void {
        $a = new ConcurrencyJob(5);
        $b = (new ConcurrencyJob(6))->delay(120);
        $c = new ConcurrencyJob(7);
        foreach ([$a, $b, $c] as $job) {
            $this->queue->push($job);
        }
        $reserved = $this->queue->pop('parallel'); // $a stays reserved

        expect($this->queue->queues())->toContain('parallel')
            ->and(array_keys($this->queue->jobs('parallel')))->toEqualCanonicalizing([$a->getId(), $b->getId(), $c->getId()]);

        $taken = $this->queue->takePending('parallel');
        $ids = array_map(fn (array $item): string => $item['job']->getId(), $taken);

        expect($ids)->toEqualCanonicalizing([$b->getId(), $c->getId()])
            ->and($this->queue->size('parallel'))->toBe(1)
            ->and($reserved?->getId())->toBe($a->getId());
    });

    it('never processes a job twice with parallel worker processes', function (): void {
        $ids = [];
        for ($i = 0; $i < 120; $i++) {
            $job = new ConcurrencyJob($i);
            $this->queue->push($job);
            $ids[] = $job->getId();
        }

        $script = dirname(__DIR__).'/Support/concurrent-redis-worker.php';
        $processes = [];
        $pipes = [];
        for ($w = 0; $w < 4; $w++) {
            $processes[] = proc_open(
                [PHP_BINARY, $script, $this->redisConfig['host'], (string) $this->redisConfig['port'], $this->redisConfig['prefix']],
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes[$w],
            );
        }

        $handled = [];
        foreach ($processes as $w => $process) {
            $out = stream_get_contents($pipes[$w][1]);
            $err = stream_get_contents($pipes[$w][2]);
            proc_close($process);
            expect($err)->toBe('');
            $handled = array_merge($handled, array_filter(explode("\n", $out)));
        }

        expect(count($handled))->toBe(120)
            ->and(count(array_unique($handled)))->toBe(120)
            ->and($handled)->toEqualCanonicalizing($ids)
            ->and($this->queue->size('parallel'))->toBe(0);

        expect($this->queue->getConnectionInfo()['client_type'])->toBeIn(['phpredis', 'predis']);
    });
});
