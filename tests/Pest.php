<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
*/

uses()
    ->beforeEach(function (): void {
        \Brain\Monkey\setUp();
    })
    ->afterEach(function (): void {
        \Brain\Monkey\tearDown();
    })
    ->in('Unit', 'Feature');

// Unit tests: fresh real-SQL database (SQLite) per test for the queue table and worker lock.
uses()
    ->beforeEach(function (): void {
        $GLOBALS['wpdb'] = new \WPQueue\Tests\Support\SqliteWpdb();
        \WPQueue\Support\Schema::markReady();
    })
    ->in('Unit');

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
*/

function fakeWpOption(string $key, mixed $value): void
{
    \Brain\Monkey\Functions\expect('get_option')
        ->with($key, \Mockery::any())
        ->andReturn($value);
}

function fakeWpTransient(string $key, mixed $value): void
{
    \Brain\Monkey\Functions\expect('get_transient')
        ->with($key)
        ->andReturn($value);
}
