<?php

declare(strict_types=1);

namespace WPQueue\Admin;

use WPQueue\Runtime\Runner;
use WPQueue\Support\DriverSwitcher;
use WPQueue\Support\Options;
use WPQueue\Support\Settings;
use WPQueue\WPQueue;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Admin actions: switch storage driver, select runner, re-check loopback.
 */
final class SettingsController
{
    public const NONCE_DRIVER = 'wp_queue_set_driver';

    public const NONCE_RUNNER = 'wp_queue_set_runner';

    public function __construct()
    {
        add_action('admin_post_wp_queue_set_driver', [$this, 'setDriver']);
        add_action('admin_post_wp_queue_set_runner', [$this, 'setRunner']);
    }

    public function setDriver(): void
    {
        $this->authorize(self::NONCE_DRIVER);

        $driver = isset($_POST['driver']) ? sanitize_key(wp_unslash($_POST['driver'])) : '';
        $result = (new DriverSwitcher(WPQueue::manager()))->switchTo($driver, self::toConfig());

        self::flash($result);
        $this->back('queues', 'drivers');
    }

    public function setRunner(): void
    {
        $this->authorize(self::NONCE_RUNNER);

        $runner = isset($_POST['runner']) ? sanitize_key(wp_unslash($_POST['runner'])) : '';

        if (! in_array($runner, array_merge([Runner::AUTO], Runner::all()), true)) {
            self::flash(['ok' => false, 'message' => __('Unknown runner.', 'wp-queue')]);
            $this->back('system', 'status');
        }

        // Re-check loopback now: it decides availability in "auto" and "loopback".
        self::refreshLoopbackCheck();

        if ($runner === Runner::LOOPBACK) {
            $availability = Runner::availability(Runner::LOOPBACK);

            if (! $availability['available']) {
                self::flash(['ok' => false, 'message' => $availability['reason']]);
                $this->back('system', 'status');
            }
        }

        $result = Settings::persist('runner', $runner, self::toConfig());
        Runner::reset();

        if ($result['ok'] && $runner === Runner::DAEMON && ! Runner::availability(Runner::DAEMON)['available']) {
            $result['message'] .= ' '.__('No fresh heartbeat from the daemon / system cron yet: add the crontab line shown below, otherwise jobs will not run.', 'wp-queue');
            $result['warning'] = true;
        }

        self::flash($result);
        $this->back('system', 'status');
    }

    /**
     * Run a loopback test and remember the result for runner auto-selection.
     *
     * @return array{status: string, message: string}
     */
    public static function refreshLoopbackCheck(): array
    {
        $check = (new SystemStatus())->checkLoopback();
        Options::setTransient(Runner::LOOPBACK_CHECK_TRANSIENT, $check, HOUR_IN_SECONDS);
        Runner::reset();

        return $check;
    }

    /**
     * Suggested crontab line for the daemon / system cron runner.
     */
    public static function cronCommand(): string
    {
        return sprintf(
            '* * * * * wp --path=%s queue work --all --limit=50 --max-time=50 >/dev/null 2>&1',
            escapeshellarg(untrailingslashit(ABSPATH)),
        );
    }

    /**
     * @param  array<string, mixed>  $result
     */
    public static function flash(array $result): void
    {
        set_transient('wp_queue_notice_'.get_current_user_id(), $result, 120);
    }

    public static function renderNotice(): void
    {
        $key = 'wp_queue_notice_'.get_current_user_id();
        $notice = get_transient($key);

        if (! is_array($notice)) {
            return;
        }

        delete_transient($key);

        $class = ! empty($notice['ok']) ? (! empty($notice['warning']) ? 'notice-warning' : 'notice-success') : 'notice-error';
        ?>
        <div class="notice <?php echo esc_attr($class); ?>" style="margin: 0 0 20px;">
            <p><?php echo esc_html((string) ($notice['message'] ?? '')); ?></p>
            <?php if (! empty($notice['snippet'])) { ?>
                <pre><code><?php echo esc_html((string) $notice['snippet']); ?></code></pre>
            <?php } ?>
        </div>
        <?php
    }

    /**
     * "Store in wp-config.php" checkbox: null when not shown.
     */
    private static function toConfig(): ?bool
    {
        if (! isset($_POST['store_present'])) {
            return null;
        }

        return ! empty($_POST['store_in_config']);
    }

    /**
     * Checkbox for the forms; shown only when the WP Queue block can be written.
     */
    public static function renderStoreCheckbox(string $key): void
    {
        if (! Settings::configWritable()) {
            return;
        }

        $setting = $key === 'runner' ? Settings::runner() : Settings::driver();
        ?>
        <input type="hidden" name="store_present" value="1">
        <label style="display:block;margin-top:6px;">
            <input type="checkbox" name="store_in_config" value="1" <?php checked($setting['source'], Settings::SOURCE_MANAGED); ?>>
            <?php echo esc_html__('Store in wp-config.php (WP Queue block; edited atomically with a backup and a check)', 'wp-queue'); ?>
        </label>
        <?php
    }

    private function authorize(string $nonce): void
    {
        if (! current_user_can('manage_options')) {
            wp_die(esc_html__('You are not allowed to change WP Queue settings.', 'wp-queue'), 403);
        }

        check_admin_referer($nonce);
    }

    private function back(string $tab, string $section): never
    {
        wp_safe_redirect(admin_url('admin.php?page=wp-queue&tab='.$tab.'&section='.$section));
        exit;
    }
}
