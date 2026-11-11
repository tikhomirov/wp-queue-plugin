<?php

declare(strict_types=1);

namespace WPQueue\Support;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Edits only WP Queue's own block in wp-config.php:
 *
 *     // BEGIN WP Queue
 *     define('WP_QUEUE_DRIVER', 'redis');
 *     // END WP Queue
 *
 * The rest of the file is never changed. Writes are atomic (temp file + rename,
 * same permissions/owner), a backup is kept until the new config is verified
 * (syntax + a fresh WordPress request), and restored on failure.
 */
final class ConfigFile
{
    public const BEGIN = '// BEGIN WP Queue';

    public const END = '// END WP Queue';

    public const PROBE_ACTION = 'wp_queue_config_probe';

    public const PROBE_TRANSIENT = 'wp_queue_config_probe';

    /**
     * Test seam / override for the wp-config.php location.
     */
    public static ?string $path = null;

    /**
     * Test seam for verification of the written file (true or error message).
     *
     * @var (callable(array<string, string|bool>): (true|string))|null
     */
    public static $verifier = null;

    /**
     * @var array<string, string|bool>|null
     */
    private static ?array $managedCache = null;

    /**
     * Block contents as loaded at the start of this request, once the file
     * has been rewritten (their runtime constants are stale after that).
     *
     * @var array<string, scalar>|null
     */
    private static ?array $loadedManaged = null;

    public static function path(): ?string
    {
        if (self::$path !== null) {
            return self::$path;
        }

        if (! defined('ABSPATH')) {
            return null;
        }

        if (file_exists(ABSPATH.'wp-config.php')) {
            return ABSPATH.'wp-config.php';
        }

        $parent = dirname(ABSPATH).'/wp-config.php';

        if (file_exists($parent) && ! file_exists(dirname(ABSPATH).'/wp-settings.php')) {
            return $parent;
        }

        return null;
    }

    /**
     * Constants defined inside the managed block.
     *
     * @return array<string, string|bool>
     */
    public static function managedValues(): array
    {
        if (self::$managedCache !== null) {
            return self::$managedCache;
        }

        $path = self::path();
        $content = $path !== null && is_readable($path) ? (string) file_get_contents($path) : '';

        return self::$managedCache = self::parse($content);
    }

    /**
     * @return array<string, string|bool>
     */
    public static function parse(string $content): array
    {
        $block = self::extractBlock($content);

        if ($block === null) {
            return [];
        }

        preg_match_all(
            "/define\\(\\s*'(WP_QUEUE_[A-Z_]+)'\\s*,\\s*(?:'([^']*)'|(true|false))\\s*\\)\\s*;/i",
            $block,
            $matches,
            PREG_SET_ORDER,
        );

        $values = [];

        foreach ($matches as $match) {
            $values[$match[1]] = isset($match[3]) && $match[3] !== ''
                ? strtolower($match[3]) === 'true'
                : $match[2];
        }

        return $values;
    }

    public static function hasBlock(?string $content = null): bool
    {
        if ($content === null) {
            $path = self::path();
            $content = $path !== null && is_readable($path) ? (string) file_get_contents($path) : '';
        }

        return self::extractBlock($content) !== null;
    }

    /**
     * @param  array<string, string|bool>  $defines
     */
    public static function block(array $defines): string
    {
        $lines = [self::BEGIN, '// Managed by WP Queue (Tools > Queues). Edit in the admin or here.'];

        foreach ($defines as $name => $value) {
            if (! preg_match('/^WP_QUEUE_[A-Z_]+$/', (string) $name)) {
                continue;
            }

            $export = is_bool($value) ? ($value ? 'true' : 'false') : "'".str_replace(['\\', "'"], ['\\\\', "\\'"], (string) $value)."'";
            $lines[] = sprintf("define('%s', %s);", $name, $export);
        }

        $lines[] = self::END;

        return implode("\n", $lines)."\n";
    }

    /**
     * Content with the managed block replaced/inserted (or removed if empty).
     *
     * @param  array<string, string|bool>  $defines
     */
    public static function render(string $content, array $defines): ?string
    {
        $pattern = '/^[ \t]*'.preg_quote(self::BEGIN, '/').'.*?^[ \t]*'.preg_quote(self::END, '/')."[^\n]*\n?(\n)?/ms";
        $block = $defines === [] ? '' : self::block($defines);

        if (preg_match($pattern, $content)) {
            // The blank line written after the block goes away with it.
            return (string) preg_replace_callback(
                $pattern,
                static fn (array $m): string => $block === '' ? '' : $block.($m[1] ?? ''),
                $content,
                1,
            );
        }

        if ($block === '') {
            return $content;
        }

        // Insert before "That's all, stop editing!" or before wp-settings.php is loaded.
        $anchors = [
            "/^.*That's all, stop editing.*$/mi",
            "/^.*require(?:_once)?\\s*\\(?\\s*ABSPATH\\s*\\.\\s*['\"]wp-settings\\.php['\"].*$/mi",
        ];

        foreach ($anchors as $anchor) {
            if (preg_match($anchor, $content, $match, PREG_OFFSET_CAPTURE)) {
                $offset = (int) $match[0][1];

                return substr($content, 0, $offset).$block."\n".substr($content, $offset);
            }
        }

        return null;
    }

    /**
     * @param  array<string, string|bool>  $defines
     * @return array{ok: bool, message: string, snippet?: string}
     */
    public static function write(array $defines): array
    {
        $before = self::managedValues();
        $snippet = self::block($defines);
        $path = self::path();

        $fail = static fn (string $message): array => ['ok' => false, 'message' => $message, 'snippet' => $snippet];

        if ($path === null) {
            return $fail(__('wp-config.php was not found.', 'wp-queue'));
        }

        $dir = dirname($path);

        if (! is_writable($path) || ! is_writable($dir)) {
            return $fail(__('wp-config.php (or its directory) is not writable. Paste this block into wp-config.php manually, above the "That\'s all, stop editing!" line:', 'wp-queue'));
        }

        $content = (string) file_get_contents($path);
        $new = self::render($content, $defines);

        if ($new === null) {
            return $fail(__('Could not find where to insert the block in wp-config.php. Add it manually above the "That\'s all, stop editing!" line:', 'wp-queue'));
        }

        if ($new === $content) {
            return ['ok' => true, 'message' => __('wp-config.php is already up to date.', 'wp-queue')];
        }

        if (! self::syntaxOk($new)) {
            return $fail(__('The resulting wp-config.php would not be valid PHP; nothing was changed.', 'wp-queue'));
        }

        $perms = fileperms($path) & 0777;
        $owner = fileowner($path);
        $group = filegroup($path);
        $suffix = bin2hex(random_bytes(4));
        // ".php" suffix: if the web server exposes the directory, the file is
        // executed rather than served as text, so secrets do not leak.
        $backup = $dir.'/.wp-config.wp-queue-backup-'.$suffix.'.php';
        $tmp = $dir.'/.wp-config.wp-queue-tmp-'.$suffix.'.php';

        if (! @copy($path, $backup)) {
            return $fail(__('Could not create a backup of wp-config.php; nothing was changed.', 'wp-queue'));
        }

        @chmod($backup, $perms & 0770);

        if (@file_put_contents($tmp, $new, LOCK_EX) !== strlen($new)) {
            @unlink($tmp);
            @unlink($backup);

            return $fail(__('Could not write a temporary file next to wp-config.php; nothing was changed.', 'wp-queue'));
        }

        @chmod($tmp, $perms);

        if (function_exists('posix_geteuid') && fileowner($tmp) !== $owner) {
            @chown($tmp, $owner);
        }

        if (filegroup($tmp) !== $group) {
            @chgrp($tmp, $group);
        }

        clearstatcache(true, $tmp);

        if (fileowner($tmp) !== $owner || filegroup($tmp) !== $group || (fileperms($tmp) & 0777) !== $perms) {
            @unlink($tmp);
            @unlink($backup);

            return $fail(__('Could not keep the owner/permissions of wp-config.php; nothing was changed. Paste the block manually:', 'wp-queue'));
        }

        if (! @rename($tmp, $path)) {
            @unlink($tmp);
            @unlink($backup);

            return $fail(__('Could not replace wp-config.php (read-only or bind-mounted file); nothing was changed. Paste the block manually:', 'wp-queue'));
        }

        self::$managedCache = null;
        self::$loadedManaged ??= $before;

        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($path, true);
        }

        $verified = self::verify($defines);

        if ($verified !== true) {
            @rename($backup, $path);

            if (function_exists('opcache_invalidate')) {
                @opcache_invalidate($path, true);
            }

            self::$managedCache = null;

            return $fail(sprintf(
                /* translators: %s: error */
                __('WordPress did not load with the new wp-config.php (%s); the previous file was restored.', 'wp-queue'),
                $verified,
            ));
        }

        @unlink($backup);

        return ['ok' => true, 'message' => __('wp-config.php updated and verified.', 'wp-queue')];
    }

    public static function syntaxOk(string $php): bool
    {
        try {
            token_get_all($php, TOKEN_PARSE);

            return true;
        } catch (\ParseError) {
            return false;
        }
    }

    /**
     * Ask a fresh WordPress request which managed constants it sees.
     *
     * @param  array<string, string|bool>  $defines
     */
    private static function verify(array $defines): true|string
    {
        if (self::$verifier !== null) {
            return (self::$verifier)($defines);
        }

        $token = bin2hex(random_bytes(16));
        set_transient(self::PROBE_TRANSIENT, $token, 120);

        $response = wp_remote_post(admin_url('admin-ajax.php'), [
            'timeout' => 15,
            'sslverify' => apply_filters('https_local_ssl_verify', false),
            'body' => ['action' => self::PROBE_ACTION, 'token' => $token],
        ]);

        delete_transient(self::PROBE_TRANSIENT);

        if (is_wp_error($response)) {
            return $response->get_error_message();
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $data = json_decode((string) wp_remote_retrieve_body($response), true);

        if ($code !== 200 || ! is_array($data) || ! isset($data['constants'])) {
            /* translators: %d: HTTP status */
            return sprintf(__('HTTP %d', 'wp-queue'), $code);
        }

        foreach ($defines as $name => $value) {
            if (($data['constants'][$name] ?? null) !== $value) {
                /* translators: %s: constant name */
                return sprintf(__('%s has an unexpected value', 'wp-queue'), $name);
            }
        }

        return true;
    }

    /**
     * admin-ajax handler for the verification request.
     */
    public static function handleProbe(): void
    {
        $token = isset($_POST['token']) ? sanitize_text_field(wp_unslash($_POST['token'])) : '';
        $expected = get_transient(self::PROBE_TRANSIENT);

        if (! is_string($expected) || $token === '' || ! hash_equals($expected, $token)) {
            wp_send_json(['error' => 'forbidden'], 403);
        }

        $constants = [];

        foreach (array_keys(self::managedValues()) as $name) {
            $constants[$name] = defined($name) ? constant($name) : null;
        }

        wp_send_json(['constants' => $constants]);
    }

    /**
     * Whether the block was rewritten during this request.
     */
    public static function rewritten(): bool
    {
        return self::$loadedManaged !== null;
    }

    /**
     * Names whose runtime constant came from the block and may no longer match the file.
     *
     * @return list<string>
     */
    public static function staleNames(): array
    {
        return self::$loadedManaged === null ? [] : array_keys(self::$loadedManaged);
    }

    public static function resetCache(): void
    {
        self::$managedCache = null;
        self::$loadedManaged = null;
    }

    private static function extractBlock(string $content): ?string
    {
        $begin = strpos($content, self::BEGIN);

        if ($begin === false) {
            return null;
        }

        $end = strpos($content, self::END, $begin);

        return $end === false ? null : substr($content, $begin, $end - $begin);
    }
}
