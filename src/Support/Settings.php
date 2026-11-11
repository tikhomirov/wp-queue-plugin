<?php

declare(strict_types=1);

namespace WPQueue\Support;

use WPQueue\Runtime\Runner;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Admin-selectable settings: storage driver and queue runner.
 *
 * Priority: wp-config.php constant > plugin option > default.
 * A constant written by WP Queue itself (inside the `// BEGIN WP Queue` block of
 * wp-config.php) stays editable from the admin; any other constant locks the field.
 */
final class Settings
{
    public const OPTION = 'wp_queue_settings';

    public const SOURCE_CONSTANT = 'constant';

    public const SOURCE_MANAGED = 'managed';

    public const SOURCE_OPTION = 'option';

    public const SOURCE_DEFAULT = 'default';

    public const DRIVERS = ['database', 'redis', 'memcached'];

    /**
     * Setting => wp-config constants that define it (first defined wins).
     */
    public const CONSTANTS = [
        'driver' => ['WP_QUEUE_DRIVER'],
        'runner' => ['WP_QUEUE_RUNNER', 'WP_QUEUE_RUNTIME_MODE'],
    ];

    /**
     * Test seam: replaces defined()/constant().
     *
     * @var (callable(string): mixed)|null
     */
    public static $constantReader = null;

    /**
     * @return array{value: string, source: string, constant: string|null}
     */
    public static function driver(): array
    {
        return self::resolve('driver', 'database');
    }

    /**
     * @return array{value: string, source: string, constant: string|null}
     */
    public static function runner(): array
    {
        $resolved = self::resolve('runner', Runner::AUTO);
        $resolved['value'] = Runner::normalize($resolved['value']);

        return $resolved;
    }

    /**
     * @return array{value: string, source: string, constant: string|null}
     */
    public static function resolve(string $key, string $default): array
    {
        foreach (self::CONSTANTS[$key] ?? [] as $name) {
            if (ConfigFile::rewritten()) {
                // The block was rewritten in this request: the file is the truth.
                $managed = ConfigFile::managedValues();

                if (array_key_exists($name, $managed)) {
                    return ['value' => (string) $managed[$name], 'source' => self::SOURCE_MANAGED, 'constant' => $name];
                }

                if (in_array($name, ConfigFile::staleNames(), true)) {
                    continue;
                }
            }

            $value = self::constant($name);

            if ($value !== null && $value !== '') {
                $managed = ConfigFile::managedValues();
                $source = array_key_exists($name, $managed) && (string) $managed[$name] === (string) $value
                    ? self::SOURCE_MANAGED
                    : self::SOURCE_CONSTANT;

                return ['value' => (string) $value, 'source' => $source, 'constant' => $name];
            }
        }

        $option = self::option();

        if (isset($option[$key]) && is_string($option[$key]) && $option[$key] !== '') {
            return ['value' => $option[$key], 'source' => self::SOURCE_OPTION, 'constant' => null];
        }

        return ['value' => $default, 'source' => self::SOURCE_DEFAULT, 'constant' => null];
    }

    public static function isLocked(string $key): bool
    {
        $resolved = $key === 'runner' ? self::runner() : self::driver();

        return $resolved['source'] === self::SOURCE_CONSTANT;
    }

    /**
     * @return array<string, mixed>
     */
    public static function option(): array
    {
        $value = Options::get(self::OPTION, []);

        return is_array($value) ? $value : [];
    }

    /**
     * @param  array<string, mixed>  $values
     */
    public static function updateOption(array $values): void
    {
        Options::set(self::OPTION, array_merge(self::option(), $values), true);
    }

    /**
     * Persist a setting.
     *
     * $toConfig: true — store it in WP Queue's block of wp-config.php,
     * false — store it in the plugin option (removing it from the block),
     * null — keep it where it is now.
     *
     * @return array{ok: bool, message: string, snippet?: string}
     */
    public static function persist(string $key, string $value, ?bool $toConfig = null): array
    {
        $resolved = $key === 'runner' ? self::runner() : self::driver();

        if ($resolved['source'] === self::SOURCE_CONSTANT) {
            return [
                'ok' => false,
                /* translators: %s: constant name */
                'message' => sprintf(__('Defined in wp-config.php (%s). Change it there.', 'wp-queue'), (string) $resolved['constant']),
            ];
        }

        $isManaged = $resolved['source'] === self::SOURCE_MANAGED;
        $toConfig ??= $isManaged;
        $constant = $resolved['constant'] ?? self::CONSTANTS[$key][0];

        if ($toConfig || $isManaged) {
            $managed = ConfigFile::managedValues();

            if ($toConfig) {
                $managed[$constant] = $value;
            } else {
                unset($managed[$constant]);
            }

            $result = ConfigFile::write($managed);

            if (! $result['ok']) {
                return $result;
            }
        }

        self::updateOption([$key => $value]);

        return ['ok' => true, 'message' => $toConfig
            ? __('Saved to wp-config.php (WP Queue block).', 'wp-queue')
            : __('Saved.', 'wp-queue')];
    }

    /**
     * Whether the managed block can be written (for the admin checkbox).
     */
    public static function configWritable(): bool
    {
        $path = ConfigFile::path();

        return $path !== null && is_writable($path) && is_writable(dirname($path));
    }

    public static function constant(string $name): mixed
    {
        if (self::$constantReader !== null) {
            return (self::$constantReader)($name);
        }

        return defined($name) ? constant($name) : null;
    }
}
