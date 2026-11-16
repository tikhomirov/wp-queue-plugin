<?php

declare(strict_types=1);

namespace WPQueue\Attributes;

if (! defined('ABSPATH')) {
    exit;
}

#[\Attribute(\Attribute::TARGET_CLASS)]
final readonly class Queue
{
    public function __construct(
        public string $name = 'default',
    ) {}
}
