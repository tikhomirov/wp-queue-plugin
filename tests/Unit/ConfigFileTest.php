<?php

declare(strict_types=1);

use WPQueue\Support\ConfigFile;

beforeEach(function (): void {
    $this->dir = sys_get_temp_dir().'/wpq-config-'.bin2hex(random_bytes(4));
    mkdir($this->dir);
    $this->file = $this->dir.'/wp-config.php';
    $this->original = "<?php\ndefine('DB_NAME', 'secret');\ndefine('DISABLE_WP_CRON', true);\n\n/* That's all, stop editing! Happy publishing. */\nrequire_once ABSPATH . 'wp-settings.php';\n";
    file_put_contents($this->file, $this->original);
    chmod($this->file, 0640);
    ConfigFile::$path = $this->file;
    ConfigFile::resetCache();
});

afterEach(function (): void {
    @chmod($this->dir, 0755);
    array_map('unlink', glob($this->dir.'/{,.}*.php', GLOB_BRACE) ?: []);
    @rmdir($this->dir);
});

function wpqLeftovers(string $dir): array
{
    return array_values(array_filter(
        glob($dir.'/.*.php') ?: [],
        static fn (string $f): bool => str_contains($f, 'wp-queue-'),
    ));
}

describe('ConfigFile', function (): void {
    it('inserts the block above "stop editing" and keeps the rest intact', function (): void {
        ConfigFile::$verifier = static fn (): bool => true;

        $result = ConfigFile::write(['WP_QUEUE_DRIVER' => 'redis', 'WP_QUEUE_LOOPBACK' => false]);
        $content = file_get_contents($this->file);

        expect($result['ok'])->toBeTrue()
            ->and($content)->toContain("// BEGIN WP Queue\n")
            ->and($content)->toContain("define('WP_QUEUE_DRIVER', 'redis');")
            ->and($content)->toContain("define('WP_QUEUE_LOOPBACK', false);")
            ->and(strpos($content, '// END WP Queue'))->toBeLessThan(strpos($content, "That's all"))
            ->and(str_replace(ConfigFile::block(['WP_QUEUE_DRIVER' => 'redis', 'WP_QUEUE_LOOPBACK' => false])."\n", '', $content))->toBe($this->original)
            ->and(fileperms($this->file) & 0777)->toBe(0640)
            ->and(wpqLeftovers($this->dir))->toBe([])
            ->and(ConfigFile::managedValues())->toBe(['WP_QUEUE_DRIVER' => 'redis', 'WP_QUEUE_LOOPBACK' => false]);
    });

    it('replaces only its own block and removes it when empty', function (): void {
        ConfigFile::$verifier = static fn (): bool => true;

        ConfigFile::write(['WP_QUEUE_DRIVER' => 'redis']);
        ConfigFile::write(['WP_QUEUE_DRIVER' => 'database']);
        $content = file_get_contents($this->file);

        expect(substr_count($content, '// BEGIN WP Queue'))->toBe(1)
            ->and($content)->toContain("define('WP_QUEUE_DRIVER', 'database');")
            ->and($content)->not->toContain("'redis'");

        ConfigFile::write([]);

        expect(file_get_contents($this->file))->toBe($this->original);
    });

    it('restores the previous file when WordPress does not load with the new one', function (): void {
        ConfigFile::$verifier = static fn (): string => 'HTTP 500';

        $result = ConfigFile::write(['WP_QUEUE_DRIVER' => 'redis']);

        expect($result['ok'])->toBeFalse()
            ->and($result['message'])->toContain('HTTP 500')
            ->and($result['snippet'])->toContain("define('WP_QUEUE_DRIVER', 'redis');")
            ->and(file_get_contents($this->file))->toBe($this->original)
            ->and(fileperms($this->file) & 0777)->toBe(0640)
            ->and(wpqLeftovers($this->dir))->toBe([]);
    });

    it('returns a snippet instead of writing when the directory is read-only', function (): void {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $this->markTestSkipped('root ignores permissions');
        }

        chmod($this->dir, 0555);

        $result = ConfigFile::write(['WP_QUEUE_DRIVER' => 'redis']);

        expect($result['ok'])->toBeFalse()
            ->and($result['snippet'])->toStartWith('// BEGIN WP Queue')
            ->and(file_get_contents($this->file))->toBe($this->original);
    });

    it('never writes invalid PHP and ignores unknown names', function (): void {
        expect(ConfigFile::syntaxOk("<?php\ndefine('A', 1);"))->toBeTrue()
            ->and(ConfigFile::syntaxOk("<?php\ndefine('A', ;"))->toBeFalse()
            ->and(ConfigFile::block(['DB_PASSWORD' => 'x', "WP_QUEUE_X');evil(" => 'y']))->not->toContain('DB_PASSWORD')
            ->and(ConfigFile::block(['WP_QUEUE_DRIVER' => "a'b"]))->toContain("'a\\'b'");
    });
});

describe('Settings storage location', function (): void {
    it('moves a setting into the block and back to the option', function (): void {
        ConfigFile::$verifier = static fn (): bool => true;

        $toConfig = \WPQueue\Support\Settings::persist('runner', 'wp_cron', true);

        expect($toConfig['ok'])->toBeTrue()
            ->and(ConfigFile::managedValues())->toBe(['WP_QUEUE_RUNNER' => 'wp_cron'])
            ->and(\WPQueue\Support\Settings::runner())->toMatchArray(['value' => 'wp_cron', 'source' => 'managed']);

        // Simulate the next request: the block constant is now defined at runtime.
        ConfigFile::resetCache();
        \WPQueue\Support\Settings::$constantReader = static fn (string $name): mixed => $name === 'WP_QUEUE_RUNNER' ? 'wp_cron' : null;

        $back = \WPQueue\Support\Settings::persist('runner', 'daemon', false);

        expect($back['ok'])->toBeTrue()
            ->and(ConfigFile::hasBlock())->toBeFalse()
            ->and(file_get_contents($this->file))->toBe($this->original)
            // The runtime constant is stale after the rewrite; the option wins.
            ->and(\WPQueue\Support\Settings::runner())->toMatchArray(['value' => 'daemon', 'source' => 'option']);
    });

    it('keeps a managed setting in the block when the location is not given', function (): void {
        ConfigFile::$verifier = static fn (): bool => true;
        ConfigFile::write(['WP_QUEUE_DRIVER' => 'database']);
        ConfigFile::resetCache();
        \WPQueue\Support\Settings::$constantReader = static fn (string $name): mixed => $name === 'WP_QUEUE_DRIVER' ? 'database' : null;

        $result = \WPQueue\Support\Settings::persist('driver', 'redis');

        expect($result['ok'])->toBeTrue()
            ->and(ConfigFile::managedValues())->toBe(['WP_QUEUE_DRIVER' => 'redis'])
            ->and(\WPQueue\Support\Settings::driver())->toMatchArray(['value' => 'redis', 'source' => 'managed']);
    });
});
