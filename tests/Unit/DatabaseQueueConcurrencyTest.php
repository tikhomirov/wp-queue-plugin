<?php

declare(strict_types=1);

use WPQueue\Queue\DatabaseQueue;
use WPQueue\Support\Lock;
use WPQueue\Support\Schema;
use WPQueue\Tests\Fixtures\ConcurrencyJob;
use WPQueue\Tests\Support\SqliteWpdb;

/**
 * Worker that lets a competitor claim first, between its SELECT and its UPDATE.
 */
class RacingDatabaseQueue extends DatabaseQueue
{
    public ?DatabaseQueue $competitor = null;

    public ?object $competitorJob = null;

    protected function claim(int $rowId, int $staleBefore): bool
    {
        if ($this->competitor !== null) {
            $this->competitorJob = $this->competitor->pop('parallel');
            $this->competitor = null;
        }

        return parent::claim($rowId, $staleBefore);
    }
}

describe('DatabaseQueue storage', function (): void {
    it('pops each job once and removes it on delete', function (): void {
        $queue = new DatabaseQueue();
        $job = new ConcurrencyJob(1);
        $queue->push($job);

        $popped = $queue->pop('parallel');

        expect($popped)->toBeInstanceOf(ConcurrencyJob::class)
            ->and($popped->getId())->toBe($job->getId())
            ->and($queue->pop('parallel'))->toBeNull()
            ->and($queue->size('parallel'))->toBe(1);

        $queue->delete($popped->getId());

        expect($queue->size('parallel'))->toBe(0);
    });

    it('does not hand the same job to a worker that lost the race', function (): void {
        $first = new ConcurrencyJob(1);
        $second = new ConcurrencyJob(2);

        $slow = new RacingDatabaseQueue();
        $slow->push($first);
        $slow->push($second);
        $slow->competitor = new DatabaseQueue();

        $slowJob = $slow->pop('parallel');

        expect($slow->competitorJob)->not->toBeNull()
            ->and($slowJob)->not->toBeNull()
            ->and($slowJob->getId())->not->toBe($slow->competitorJob->getId())
            ->and([$slowJob->getId(), $slow->competitorJob->getId()])
            ->toEqualCanonicalizing([$first->getId(), $second->getId()]);
    });

    it('re-claims a job abandoned by a crashed worker', function (): void {
        global $wpdb;

        $queue = new DatabaseQueue();
        $job = new ConcurrencyJob(1);
        $queue->push($job);
        $queue->pop('parallel');

        $wpdb->query($wpdb->prepare(
            'UPDATE wp_queue_jobs SET reserved_at = %d WHERE job_id = %s',
            time() - DatabaseQueue::STALE_THRESHOLD - 1,
            $job->getId(),
        ));

        expect($queue->pop('parallel')?->getId())->toBe($job->getId());
    });

    it('release makes a job available again after the delay', function (): void {
        $queue = new DatabaseQueue();
        $queue->push(new ConcurrencyJob(1));
        $job = $queue->pop('parallel');

        $queue->release($job, 3600);
        expect($queue->pop('parallel'))->toBeNull();

        $queue->release($job, 0);
        expect($queue->pop('parallel')?->getId())->toBe($job->getId());
    });

    it('lists queues and jobs for the admin UI', function (): void {
        $queue = new DatabaseQueue();
        $job = new ConcurrencyJob(7);
        $queue->push($job);

        $jobs = $queue->jobs('parallel');

        expect($queue->queues())->toBe(['parallel'])
            ->and(array_keys($jobs))->toBe([$job->getId()])
            ->and(unserialize($jobs[$job->getId()]['payload'])->number())->toBe(7);
    });

    it('moves 1.2.x jobs from wp_options into the table', function (): void {
        global $wpdb;

        $legacy = new ConcurrencyJob(3);
        $stored = [$legacy->getId() => ['payload' => serialize($legacy), 'available_at' => time(), 'reserved_at' => null, 'attempts' => 0]];
        $wpdb->insert('wp_options', ['option_name' => 'wp_queue_jobs_parallel', 'option_value' => 'x']);

        Brain\Monkey\Functions\when('get_option')->alias(fn ($name, $default = false) => $name === 'wp_queue_jobs_parallel' ? $stored : 0);
        Brain\Monkey\Functions\when('update_option')->justReturn(true);
        Brain\Monkey\Functions\expect('delete_option')->once()->with('wp_queue_jobs_parallel')->andReturn(true);

        Schema::install();

        expect((new DatabaseQueue())->pop('parallel')?->getId())->toBe($legacy->getId());
    });

    it('never processes a job twice with parallel worker processes', function (): void {
        $file = tempnam(sys_get_temp_dir(), 'wpq');
        $GLOBALS['wpdb'] = new SqliteWpdb($file);

        $queue = new DatabaseQueue();
        $ids = [];
        for ($i = 0; $i < 120; $i++) {
            $job = new ConcurrencyJob($i);
            $queue->push($job);
            $ids[] = $job->getId();
        }

        $script = dirname(__DIR__).'/Support/concurrent-worker.php';
        $processes = [];
        for ($w = 0; $w < 4; $w++) {
            $processes[] = proc_open([PHP_BINARY, $script, $file], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes[$w]);
        }

        $handled = [];
        foreach ($processes as $w => $process) {
            $out = stream_get_contents($pipes[$w][1]);
            $err = stream_get_contents($pipes[$w][2]);
            proc_close($process);
            expect($err)->toBe('');
            $handled = array_merge($handled, array_filter(explode("\n", $out)));
        }

        @unlink($file);
        @unlink($file.'-wal');
        @unlink($file.'-shm');

        expect(count($handled))->toBe(120)
            ->and(count(array_unique($handled)))->toBe(120)
            ->and($handled)->toEqualCanonicalizing($ids);
    });
});

describe('Worker lock', function (): void {
    it('is exclusive until released', function (): void {
        $token = Lock::acquire(Lock::WORKER, 60);

        expect($token)->toBeString()
            ->and(Lock::acquire(Lock::WORKER, 60))->toBeNull()
            ->and(Lock::isHeld(Lock::WORKER))->toBeTrue();

        Lock::release(Lock::WORKER, $token);

        expect(Lock::isHeld(Lock::WORKER))->toBeFalse()
            ->and(Lock::acquire(Lock::WORKER, 60))->toBeString();
    });

    it('can be taken over after it expires', function (): void {
        $stale = Lock::acquire(Lock::WORKER, -1);

        expect($stale)->toBeString()
            ->and(Lock::refresh(Lock::WORKER, $stale, 60))->toBeTrue();

        global $wpdb;
        $wpdb->query("UPDATE wp_queue_locks SET expires_at = 0 WHERE name = 'worker'");

        $fresh = Lock::acquire(Lock::WORKER, 60);

        expect($fresh)->toBeString()
            ->and(Lock::refresh(Lock::WORKER, $stale, 60))->toBeFalse();
    });
});
