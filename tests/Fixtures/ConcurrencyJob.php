<?php

declare(strict_types=1);

namespace WPQueue\Tests\Fixtures;

use WPQueue\Jobs\Job;

class ConcurrencyJob extends Job
{
    public function __construct(private int $number = 0)
    {
        parent::__construct();
        $this->onQueue('parallel');
    }

    public function handle(): void
    {
        // Widen the race window between claiming and deleting.
        usleep(random_int(0, 2000));
    }

    public function number(): int
    {
        return $this->number;
    }
}
