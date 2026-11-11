<?php

declare(strict_types=1);

use Brain\Monkey\Functions;
use WPQueue\Loopback\LoopbackDispatcher;
use WPQueue\Runtime\Runner;
use WPQueue\Runtime\Trigger;
use WPQueue\Support\ConfigFile;
use WPQueue\Support\Options;
use WPQueue\Support\Settings;

function withConstants(array $constants): void
{
    Settings::$constantReader = static fn (string $name): mixed => $constants[$name] ?? null;
    Runner::reset();
}

function selectRunner(string $runner): void
{
    Options::set(Settings::OPTION, ['runner' => $runner]);
    Runner::reset();
}

describe('Runner auto selection', function (): void {
    it('uses the daemon when its heartbeat is fresh', function (): void {
        Runner::beat(Runner::DAEMON, 3);
        Runner::reset();

        $resolved = Runner::resolve();

        expect($resolved['runner'])->toBe(Runner::DAEMON)
            ->and($resolved['auto'])->toBeTrue();
    });

    it('falls back to loopback when the daemon heartbeat is stale', function (): void {
        Options::set(Runner::HEARTBEAT_OPTION, [Runner::DAEMON => ['at' => time() - Runner::HEARTBEAT_TTL - 1]]);
        Runner::reset();

        expect(Runner::active())->toBe(Runner::LOOPBACK);
    });

    it('then WP-Cron when loopback fails, then sync when WP-Cron is disabled', function (): void {
        Options::setTransient(Runner::LOOPBACK_CHECK_TRANSIENT, ['status' => 'error', 'message' => 'refused'], 60);
        Runner::reset();
        expect(Runner::active())->toBe(Runner::WP_CRON);

        withConstants(['DISABLE_WP_CRON' => true]);
        expect(Runner::active())->toBe(Runner::SYNC);
    });

    it('treats WP_QUEUE_LOOPBACK=false as loopback unavailable', function (): void {
        withConstants(['WP_QUEUE_LOOPBACK' => false]);

        expect(Runner::active())->toBe(Runner::WP_CRON);
    });
});

describe('Runner manual selection', function (): void {
    it('keeps a manually selected runner even without a heartbeat', function (): void {
        selectRunner(Runner::DAEMON);

        $resolved = Runner::resolve();

        expect($resolved['runner'])->toBe(Runner::DAEMON)
            ->and($resolved['auto'])->toBeFalse();
    });

    it('falls back to auto when the selected runner is disabled in wp-config', function (): void {
        Options::set(Settings::OPTION, ['runner' => Runner::LOOPBACK]);
        withConstants(['WP_QUEUE_LOOPBACK' => false]);

        expect(Runner::active())->toBe(Runner::WP_CRON);
    });

    it('gives wp-config constants priority and maps legacy values', function (): void {
        selectRunner(Runner::SYNC);
        withConstants(['WP_QUEUE_RUNTIME_MODE' => 'daemon']);

        expect(Runner::active())->toBe(Runner::DAEMON)
            ->and(Settings::runner()['source'])->toBe(Settings::SOURCE_CONSTANT)
            ->and(Settings::isLocked('runner'))->toBeTrue()
            ->and(Settings::persist('runner', Runner::SYNC)['ok'])->toBeFalse();

        withConstants(['WP_QUEUE_RUNTIME_MODE' => 'cron_loopback']);
        expect(Runner::active())->toBe(Runner::LOOPBACK);
    });

    it('lets the CLI worker run only for daemon or auto', function (): void {
        selectRunner(Runner::AUTO);
        expect(Runner::cliWorkerAllowed())->toBeTrue();

        selectRunner(Runner::DAEMON);
        expect(Runner::cliWorkerAllowed())->toBeTrue();

        foreach ([Runner::LOOPBACK, Runner::WP_CRON, Runner::SYNC] as $runner) {
            selectRunner($runner);
            expect(Runner::cliWorkerAllowed())->toBeFalse();
        }
    });
});

describe('Only the selected runner starts workers', function (): void {
    beforeEach(function (): void {
        $this->calls = ['loopback' => 0, 'schedule' => 0];
        $calls = &$this->calls;

        Functions\when('wp_remote_post')->alias(function () use (&$calls) {
            $calls['loopback']++;

            return [];
        });
        Functions\when('wp_schedule_single_event')->alias(function () use (&$calls) {
            $calls['schedule']++;

            return true;
        });
        Functions\when('wp_next_scheduled')->justReturn(false);
        Functions\when('spawn_cron')->justReturn(null);
        Functions\when('admin_url')->justReturn('https://example.com/wp-admin/admin-ajax.php');
        Functions\when('add_query_arg')->alias(fn (array $args, string $url) => $url.'?'.http_build_query($args));
        Functions\when('wp_create_nonce')->justReturn('nonce');
        Functions\when('esc_url_raw')->returnArg();
        Functions\when('home_url')->justReturn('https://example.com');
        Functions\when('apply_filters')->alias(fn ($hook, $value) => $value);
        Functions\when('is_wp_error')->justReturn(false);
    });

    it('starts work only through the active runner', function (string $runner, array $expected): void {
        selectRunner($runner);
        Trigger::reset();

        // LoopbackDispatcher remembers spawned queues per request.
        LoopbackDispatcher::forget('q-'.$runner);
        Trigger::afterDispatch('q-'.$runner);

        expect($this->calls['loopback'])->toBe($expected['loopback'])
            ->and($this->calls['schedule'])->toBe($expected['schedule'])
            ->and(Trigger::state())->toBe($expected['state'])
            ->and(LoopbackDispatcher::isEnabled())->toBe($runner === Runner::LOOPBACK);
    })->with([
        'daemon' => [Runner::DAEMON, ['loopback' => 0, 'schedule' => 0, 'state' => ['spawn_cron' => false, 'sync' => false]]],
        'loopback' => [Runner::LOOPBACK, ['loopback' => 1, 'schedule' => 0, 'state' => ['spawn_cron' => false, 'sync' => false]]],
        'wp_cron' => [Runner::WP_CRON, ['loopback' => 0, 'schedule' => 1, 'state' => ['spawn_cron' => true, 'sync' => false]]],
        'sync' => [Runner::SYNC, ['loopback' => 0, 'schedule' => 0, 'state' => ['spawn_cron' => false, 'sync' => true]]],
    ]);

    it('does not spawn wp-cron.php with DISABLE_WP_CRON', function (): void {
        selectRunner(Runner::WP_CRON);
        withConstants(['DISABLE_WP_CRON' => true]);
        Trigger::reset();

        Trigger::afterDispatch('default');

        expect($this->calls['schedule'])->toBe(1)
            ->and(Trigger::state()['spawn_cron'])->toBeFalse();
    });
});

describe('Settings sources', function (): void {
    it('treats a constant inside the WP Queue block as editable', function (): void {
        $dir = sys_get_temp_dir().'/wpq-settings-'.bin2hex(random_bytes(3));
        mkdir($dir);
        file_put_contents($dir.'/wp-config.php', "<?php\n".ConfigFile::block(['WP_QUEUE_RUNNER' => 'daemon'])."/* That's all, stop editing! */\n");
        ConfigFile::$path = $dir.'/wp-config.php';
        ConfigFile::resetCache();
        withConstants(['WP_QUEUE_RUNNER' => 'daemon']);

        expect(Settings::runner()['source'])->toBe(Settings::SOURCE_MANAGED)
            ->and(Settings::isLocked('runner'))->toBeFalse();

        array_map('unlink', glob($dir.'/{,.}*.php', GLOB_BRACE) ?: []);
        rmdir($dir);
    });
});
