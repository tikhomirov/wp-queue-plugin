<?php

declare(strict_types=1);

namespace WPQueue\Support;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Thin wrapper over options/transients used by settings and runner state.
 * Unit tests switch it to an in-memory store.
 */
final class Options
{
    /**
     * @var array<string, mixed>|null
     */
    private static ?array $memory = null;

    public static function useMemory(array $values = []): void
    {
        self::$memory = $values;
    }

    public static function useWordPress(): void
    {
        self::$memory = null;
    }

    public static function get(string $name, mixed $default = false): mixed
    {
        if (self::$memory !== null) {
            return array_key_exists($name, self::$memory) ? self::$memory[$name] : $default;
        }

        return function_exists('get_option') ? get_option($name, $default) : $default;
    }

    public static function set(string $name, mixed $value, bool $autoload = false): void
    {
        if (self::$memory !== null) {
            self::$memory[$name] = $value;

            return;
        }

        if (function_exists('update_option')) {
            update_option($name, $value, $autoload);
        }
    }

    public static function delete(string $name): void
    {
        if (self::$memory !== null) {
            unset(self::$memory[$name]);

            return;
        }

        if (function_exists('delete_option')) {
            delete_option($name);
        }
    }

    public static function transient(string $name): mixed
    {
        if (self::$memory !== null) {
            return self::$memory['_transient_'.$name] ?? false;
        }

        return function_exists('get_transient') ? get_transient($name) : false;
    }

    public static function setTransient(string $name, mixed $value, int $ttl): void
    {
        if (self::$memory !== null) {
            self::$memory['_transient_'.$name] = $value;

            return;
        }

        if (function_exists('set_transient')) {
            set_transient($name, $value, $ttl);
        }
    }
}
