<?php

declare(strict_types=1);

use WPQueue\Contracts\JobInterface;
use WPQueue\Contracts\QueueInterface;
use WPQueue\Queue\DatabaseQueue;
use WPQueue\QueueManager;
use WPQueue\Support\DriverSwitcher;
use WPQueue\Support\Lock;
use WPQueue\Support\Options;
use WPQueue\Support\Settings;
use WPQueue\Tests\Fixtures\ConcurrencyJob;

/**
 * In-memory target queue that records what it received.
 */
function memoryQueue(): QueueInterface
{
    return new class implements QueueInterface
    {
        /** @var array<string, array{job: JobInterface, delay: int}> */
        public array $jobs = [];

        public function push(JobInterface $job): string
        {
            $this->jobs[$job->getId()] = ['job' => $job, 'delay' => $job->getDelay()];

            return $job->getId();
        }

        public function later(int $delay, JobInterface $job): string
        {
            return $this->push($job->delay($delay));
        }

        public function pop(string $queue = 'default'): ?JobInterface
        {
            return null;
        }

        public function delete(string $jobId): bool
        {
            unset($this->jobs[$jobId]);

            return true;
        }

        public function release(JobInterface $job, int $delay = 0): void {}

        public function size(string $queue = 'default'): int
        {
            return count($this->jobs);
        }

        public function clear(string $queue = 'default'): int
        {
            return 0;
        }

        public function isEmpty(string $queue = 'default'): bool
        {
            return $this->jobs === [];
        }
    };
}

describe('DriverSwitcher', function (): void {
    it('moves ready and delayed jobs, never jobs being executed', function (): void {
        $db = new DatabaseQueue();
        $ready = new ConcurrencyJob(1);
        $delayed = (new ConcurrencyJob(2))->delay(300);
        $running = new ConcurrencyJob(3);
        $db->push($running);
        $running = $db->pop('parallel'); // reserved by a worker
        $db->push($ready);
        $db->push($delayed);

        $target = memoryQueue();
        $moved = DriverSwitcher::move($db, $target);

        expect($moved)->toBe(2)
            ->and(array_keys($target->jobs))->toEqualCanonicalizing([$ready->getId(), $delayed->getId()])
            ->and($target->jobs[$delayed->getId()]['delay'])->toBeGreaterThan(290)
            ->and($db->size('parallel'))->toBe(1)
            ->and($db->reservedCount('parallel'))->toBe(1);
    });

    it('refuses to switch while a worker holds the lock or jobs are running', function (): void {
        $manager = new class extends QueueManager
        {
            public function getDriverStatus(string $driver): array
            {
                return ['status' => self::STATUS_READY, 'extension' => true, 'server' => true, 'message' => 'ok'];
            }
        };
        $manager->extend('redis', fn () => memoryQueue());

        $token = Lock::acquire(Lock::WORKER, 60);
        $busy = (new DriverSwitcher($manager))->switchTo('redis');
        Lock::release(Lock::WORKER, $token);

        expect($busy['ok'])->toBeFalse();

        $db = new DatabaseQueue();
        $db->push(new ConcurrencyJob(4));
        $db->pop('parallel');

        $running = (new DriverSwitcher($manager))->switchTo('redis');

        expect($running['ok'])->toBeFalse()
            ->and($running['message'])->toContain('parallel')
            ->and(Settings::driver()['value'])->toBe('database');
    });

    it('switches, moves the jobs and remembers the previous driver', function (): void {
        $target = memoryQueue();
        $manager = new class extends QueueManager
        {
            public function getDriverStatus(string $driver): array
            {
                return ['status' => self::STATUS_READY, 'extension' => true, 'server' => true, 'message' => 'ok'];
            }
        };
        $manager->extend('redis', fn () => $target);

        $db = new DatabaseQueue();
        $job = new ConcurrencyJob(5);
        $db->push($job);

        $result = (new DriverSwitcher($manager))->switchTo('redis');

        expect($result['ok'])->toBeTrue()
            ->and($result['moved'])->toBe(1)
            ->and(array_keys($target->jobs))->toBe([$job->getId()])
            ->and($db->size('parallel'))->toBe(0)
            ->and(Settings::driver())->toBe(['value' => 'redis', 'source' => Settings::SOURCE_OPTION, 'constant' => null])
            ->and(Options::get(DriverSwitcher::PREVIOUS_OPTION)['driver'])->toBe('database');
    });

    it('cannot change a driver defined in wp-config.php', function (): void {
        Settings::$constantReader = static fn (string $name): mixed => $name === 'WP_QUEUE_DRIVER' ? 'database' : null;

        $result = (new DriverSwitcher(new QueueManager()))->switchTo('redis');

        expect($result['ok'])->toBeFalse();
    });
});
