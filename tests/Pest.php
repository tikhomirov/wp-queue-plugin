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
        \WPQueue\Support\Options::useMemory();
        \Brain\Monkey\Functions\stubTranslationFunctions();
        \WPQueue\Support\Settings::$constantReader = static fn (string $name): mixed => null;
        \WPQueue\Support\ConfigFile::$path = null;
        \WPQueue\Support\ConfigFile::$verifier = null;
        \WPQueue\Support\ConfigFile::resetCache();
        \WPQueue\Runtime\Runner::reset();
        \WPQueue\Runtime\Trigger::reset();
    })
    ->afterEach(function (): void {
        \WPQueue\Support\Options::useWordPress();
        \WPQueue\Support\Settings::$constantReader = null;
        \WPQueue\Runtime\Runner::reset();
        \WPQueue\Runtime\Trigger::reset();
        \WPQueue\Support\ConfigFile::resetCache();
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
