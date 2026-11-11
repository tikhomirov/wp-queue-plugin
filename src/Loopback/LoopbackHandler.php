<?php

declare(strict_types=1);

namespace WPQueue\Loopback;

use WPQueue\Runtime\Runner;
use WPQueue\WPQueue;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Handles loopback requests that trigger immediate queue processing.
 *
 * Registered on admin-ajax.php (authenticated and unauthenticated) so a
 * non-blocking wp_remote_post() from LoopbackDispatcher can start the worker
 * without waiting for WP-Cron.
 */
final class LoopbackHandler
{
    public function __construct()
    {
        add_action('wp_ajax_wp_queue_loopback', [$this, 'handle']);
        add_action('wp_ajax_nopriv_wp_queue_loopback', [$this, 'handle']);
    }

    /**
     * Handle the loopback request.
     */
    public function handle(): void
    {
        // Don't lock up other requests while processing.
        session_write_close();

        $queue = LoopbackDispatcher::sanitizeQueue(wp_unslash($_REQUEST['queue'] ?? 'default'));

        if (! $this->verifyNonce($queue)) {
            wp_die('Unauthorized', 'Unauthorized', ['response' => 403]);
        }

        if (WPQueue::isProcessing($queue)) {
            wp_die('Already processing', 'Already processing', ['response' => 200]);
        }

        $processed = WPQueue::processQueues($queue, 50, 50, Runner::LOOPBACK);

        // Jobs may have been dispatched while this worker was finishing: start
        // another worker, but only if this one made progress (delayed jobs
        // must not cause an endless chain of requests).
        if ($processed !== null && $processed > 0 && self::hasPending($queue)) {
            LoopbackDispatcher::forget($queue);
            LoopbackDispatcher::spawn($queue);
        }

        wp_die('OK', 'OK', ['response' => 200]);
    }

    /**
     * The worker serves every queue, so look at all of them.
     */
    private static function hasPending(string $queue): bool
    {
        $queues = WPQueue::manager()->discoverQueues();
        $queues[] = $queue;

        foreach (array_unique($queues) as $name) {
            if (WPQueue::queueSize($name) > 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Verify the loopback nonce.
     */
    protected function verifyNonce(string $queue): bool
    {
        $nonce = wp_unslash($_REQUEST['nonce'] ?? '');

        if (empty($nonce)) {
            return false;
        }

        return wp_verify_nonce(sanitize_text_field($nonce), LoopbackDispatcher::nonceAction($queue)) !== false;
    }
}
