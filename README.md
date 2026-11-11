<p align="center">
  <img src="assets/images/logo.svg" alt="WP Queue Logo" width="150" height="150">
</p>

<h1 align="center">WP Queue</h1>

<p align="center">
  <strong>Laravel Horizon-like queue manager for WordPress</strong><br>
  Simple, powerful, and modern background job processing.
</p>

<p align="center">
  <a href="https://packagist.org/packages/rwsite/wp-queue"><img src="https://img.shields.io/packagist/v/rwsite/wp-queue.svg?style=flat-square" alt="Latest Version"></a>
  <a href="https://packagist.org/packages/rwsite/wp-queue"><img src="https://img.shields.io/packagist/php-v/rwsite/wp-queue.svg?style=flat-square" alt="PHP Version"></a>
  <a href="https://github.com/rwsite/wp-queue/actions"><img src="https://img.shields.io/github/actions/workflow/status/rwsite/wp-queue/tests.yml?branch=main&style=flat-square" alt="Build Status"></a>
  <a href="https://codecov.io/gh/rwsite/wp-queue"><img src="https://img.shields.io/codecov/c/github/rwsite/wp-queue?style=flat-square" alt="Code Coverage"></a>
  <a href="LICENSE"><img src="https://img.shields.io/badge/license-GPL--2.0--or--later-blue.svg?style=flat-square" alt="License"></a>
</p>

<p align="center">
  <a href="#features">Features</a> •
  <a href="#installation">Installation</a> •
  <a href="#quick-start">Quick Start</a> •
  <a href="#admin-ui">Admin UI</a> •
  <a href="#rest-api">REST API</a> •
  <a href="#testing">Testing</a> •
  <a href="README.ru.md">RU version</a>
</p>

---

## Features

- 🚀 **Laravel-style API** — Clean, fluent job dispatching with PHP 8 attributes
- 📦 **Multiple Drivers** — Database, Redis, Memcached; runner (daemon, loopback, WP-Cron, sync) chosen in the admin or picked automatically
- 🔄 **Auto Retries** — Exponential backoff for failed jobs
- ⏰ **Scheduling** — Cron-like job scheduling
- 👁️ **WP-Cron Monitor** — View, run, pause, resume, edit cron events
- 📊 **Dashboard** — Beautiful admin UI with statistics
- 🔌 **REST API** — Full API for integrations
- 💻 **WP-CLI** — Complete command-line interface
- 🌍 **i18n Ready** — Translations included (Russian)

## Requirements

- PHP 8.3+
- WordPress 6.0+

## Installation

1. Clone or download to `wp-content/plugins/wp-queue`
2. Run `composer install`
3. Activate the plugin

## Quick Start

### Create a Job

```php
use WPQueue\Jobs\Job;
use WPQueue\Attributes\{Schedule, Queue, Timeout, Retries};

#[Schedule('hourly')]
#[Queue('imports')]
#[Timeout(120)]
#[Retries(5)]
class ImportProductsJob extends Job
{
    public function __construct(
        private array $productIds
    ) {
        parent::__construct();
    }

    public function handle(): void
    {
        foreach ($this->productIds as $id) {
            // Import logic here
        }
    }
    
    public function failed(\Throwable $e): void
    {
        // Handle failure
        error_log('Import failed: ' . $e->getMessage());
    }
}
```

### Dispatch Jobs

```php
use WPQueue\WPQueue;

// Dispatch to queue
WPQueue::dispatch(new ImportProductsJob([1, 2, 3]));

// With delay (seconds)
WPQueue::dispatch(new ImportProductsJob($ids))->delay(60);

// To specific queue
WPQueue::dispatch(new ImportProductsJob($ids))->onQueue('high-priority');

// Synchronous (bypass queue)
WPQueue::dispatchSync(new ImportProductsJob($ids));

// Chain jobs
WPQueue::chain([
    new FetchProductsJob(),
    new ImportProductsJob($ids),
    new NotifyAdminJob(),
])->dispatch();

// Batch jobs
WPQueue::batch([
    new ImportProductJob($id1),
    new ImportProductJob($id2),
    new ImportProductJob($id3),
])->dispatch();
```

### Schedule Jobs

```php
// In your plugin
add_action('wp_queue_schedule', function ($scheduler) {
    // Using attributes (automatic)
    $scheduler->job(ImportProductsJob::class);
    
    // Manual scheduling
    $scheduler->job(CleanupJob::class)->daily();
    
    // Custom interval
    $scheduler->job(CheckStopListJob::class)->everyMinutes(15);
    
    // Conditional
    $scheduler->job(SyncJob::class)
        ->hourly()
        ->when(fn() => get_option('sync_enabled'));
    
    // From settings
    $scheduler->job(BackupJob::class)
        ->interval(get_option('backup_interval', 'daily'));

    // One-time execution (at specific timestamp)
    $scheduler->job(OneTimeJob::class)->at(time() + 3600);
});
```

## PHP 8 Attributes

### `#[Schedule]`

```php
#[Schedule('hourly')]                    // Fixed interval
#[Schedule('daily', setting: 'my_opt')]  // From wp_options
```

Available intervals: `min`, `5min`, `10min`, `15min`, `30min`, `hourly`, `2hourly`, `3hourly`, `6hourly`, `8hourly`, `12hourly`, `daily`, `twicedaily`, `weekly`

### `#[Queue]`

```php
#[Queue('default')]    // Queue name
#[Queue('high')]       // Priority queue
```

### `#[Timeout]`

```php
#[Timeout(60)]   // 60 seconds
#[Timeout(300)]  // 5 minutes
```

### `#[Retries]`

```php
#[Retries(3)]   // Max 3 attempts
#[Retries(5)]   // Max 5 attempts
```

### `#[UniqueJob]`

```php
#[UniqueJob]           // Prevent duplicate jobs
#[UniqueJob(key: 'custom_key')]
```

## Events (Hooks)

```php
// Before job execution
add_action('wp_queue_job_processing', function($event) {
    // $event->job, $event->queue
});

// After successful execution
add_action('wp_queue_job_processed', function($event) {
    // $event->job, $event->queue
});

// On failure
add_action('wp_queue_job_failed', function($event) {
    // $event->job, $event->queue, $event->exception
});

// On retry
add_action('wp_queue_job_retrying', function($event) {
    // $event->job, $event->queue, $event->attempt, $event->exception
});
```

## Queue Management

```php
use WPQueue\WPQueue;

// Check status
WPQueue::isProcessing('default');   // bool
WPQueue::isPaused('default');       // bool
WPQueue::queueSize('default');      // int

// Control
WPQueue::pause('default');
WPQueue::resume('default');
WPQueue::cancel('default');   // Clear + pause
WPQueue::clear('default');    // Remove all jobs

// Logs
WPQueue::logs()->recent(100);
WPQueue::logs()->failed();
WPQueue::logs()->forJob(ImportProductsJob::class);
WPQueue::logs()->metrics();
WPQueue::logs()->clearOld(7);  // Clear logs older than 7 days
```

## Admin UI

Access via **WP Admin → WP Queue**

### Tabs

| Tab | Description |
|-----|-------------|
| **Dashboard** | Stats (pending, running, completed, failed), queue management |
| **Scheduled Jobs** | WP Queue jobs with "Run Now" button |
| **Logs** | Execution history with filtering |
| **WP-Cron** | All WordPress cron events monitoring |
| **System** | Environment status and health check |

### WP-Cron Monitor

View and manage all WordPress cron events:

- Filter by source (WordPress, WooCommerce, Plugins, WP Queue)
- See overdue events
- Run events manually
- Delete scheduled events
- View registered schedules

### System Status

Health check and environment info:

- PHP/WordPress versions
- Memory limit and usage
- WP-Cron status (disabled/alternate)
- Loopback request check
- Action Scheduler stats (if installed)
- Timezone and time info

## REST API

```http
GET    /wp-json/wp-queue/v1/queues
POST   /wp-json/wp-queue/v1/queues/{queue}/pause
POST   /wp-json/wp-queue/v1/queues/{queue}/resume
POST   /wp-json/wp-queue/v1/queues/{queue}/clear
POST   /wp-json/wp-queue/v1/jobs/{job}/run
GET    /wp-json/wp-queue/v1/logs
POST   /wp-json/wp-queue/v1/logs/clear
GET    /wp-json/wp-queue/v1/metrics

# Cron & System
GET    /wp-json/wp-queue/v1/cron
POST   /wp-json/wp-queue/v1/cron/run
POST   /wp-json/wp-queue/v1/cron/delete
GET    /wp-json/wp-queue/v1/system
```

## Queue Drivers and Runners

Two independent settings, both chosen in **Queues → Drivers** and **System → Status** (or with `wp queue config`):

* **Storage driver** — where jobs are kept.
* **Runner** — what executes them. Exactly one runner is active; the others register no hooks and send no requests.

| Driver | Description | Requirements |
|--------|-------------|-------------|
| `database` | `{prefix}queue_jobs` table, atomic claim (default) | None |
| `redis` | Lua scripts: atomic claim, delayed/reserved sets, crash recovery | phpredis or Predis (Redis Object Cache) |
| `memcached` | Not recommended: jobs can't be listed or moved | memcached extension |

| Runner | How jobs start |
|--------|----------------|
| `auto` (default) | Fastest available: `daemon` if its heartbeat is fresh (< 10 min), else `loopback`, `wp_cron`, `sync`. The admin shows the actual runner and why |
| `daemon` | System cron or a daemon runs `wp queue work --all` (crontab line and heartbeat are shown in the admin) |
| `loopback` | Non-blocking POST to `admin-ajax.php` after dispatch (like Action Scheduler) |
| `wp_cron` | `wp_queue_process` WP-Cron event |
| `sync` | At the end of the request that dispatched the job |

Switching the driver with **Make active** moves waiting jobs (ready and delayed) to the new backend under the global worker lock; it is refused while jobs are being executed.

### Configuration

Precedence: constant in `wp-config.php` → plugin option → default.

```php
define('WP_QUEUE_DRIVER', 'redis');   // database | redis | memcached
define('WP_QUEUE_RUNNER', 'daemon');  // auto | daemon | loopback | wp_cron | sync
// Legacy, still honoured: WP_QUEUE_RUNTIME_MODE ('daemon' | 'cron_loopback'), WP_QUEUE_LOOPBACK (false disables loopback)

// Redis settings (compatible with redis-cache plugin)
define('WP_REDIS_HOST', 'redis');      // or '127.0.0.1'
define('WP_REDIS_PORT', '6379');
define('WP_REDIS_PREFIX', 'mysite_');  // optional
define('WP_REDIS_PASSWORD', 'secret'); // optional
define('WP_REDIS_DATABASE', 0);        // optional, 0-15

// Memcached settings
define('WP_MEMCACHED_HOST', '127.0.0.1');
define('WP_MEMCACHED_PORT', 11211);
```

A constant anywhere in `wp-config.php` locks the setting in the admin ("set in wp-config.php"). Constants inside the plugin's own block stay editable from the admin:

```php
// BEGIN WP Queue
// Managed by WP Queue (Tools > Queues). Edit in the admin or here.
define('WP_QUEUE_DRIVER', 'redis');
// END WP Queue
```

The plugin rewrites only this block: temp file + atomic rename, owner and permissions preserved, backup, PHP syntax check and a WordPress load check over HTTP; on failure the backup is restored. If the file is not writable, the admin shows the block to paste manually. Requires `manage_options`; every form has a nonce.

```bash
wp queue config                                   # show driver, runner, reason, heartbeat
wp queue config --driver=redis --store=wp-config  # move jobs, save in the WP Queue block
wp queue config --runner=daemon                   # save in the plugin option
```

See [docs/runners-and-drivers.md](docs/runners-and-drivers.md) for details.

### Programmatic Configuration

```php
use WPQueue\WPQueue;
use WPQueue\Contracts\QueueInterface;

// Set driver programmatically
WPQueue::manager()->setDefaultDriver('redis');

// Check available drivers
$drivers = WPQueue::manager()->getAvailableDrivers();
// ['database' => ['available' => true, 'info' => '...'], ...]

// Check if specific driver is available
if (WPQueue::manager()->isDriverAvailable('redis')) {
    WPQueue::manager()->setDefaultDriver('redis');
}

// Register custom driver
WPQueue::manager()->extend('sqs', function() {
    return new SqsQueue(['region' => 'us-east-1']);
});
```

### Redis with redis-cache Plugin

If you use [Redis Object Cache](https://github.com/rhubarbgroup/redis-cache) plugin, WP Queue will automatically use the same Redis connection settings. No additional configuration needed!

```php
// These constants from redis-cache are automatically used:
// WP_REDIS_HOST, WP_REDIS_PORT, WP_REDIS_PASSWORD, WP_REDIS_DATABASE
// WP_REDIS_PREFIX, WP_REDIS_SCHEME, WP_REDIS_PATH, WP_REDIS_TIMEOUT
```

## Migration from Action Scheduler

### Before (Action Scheduler)

```php
as_schedule_recurring_action(time(), HOUR_IN_SECONDS, 'my_hourly_task');

add_action('my_hourly_task', function() {
    // task code
});
```

### After (WP Queue)

```php
#[Schedule('hourly')]
class MyHourlyTask extends Job
{
    public function handle(): void
    {
        // task code
    }
}

// Register
add_action('wp_queue_schedule', fn($s) => $s->job(MyHourlyTask::class));
```

## Comparison with Action Scheduler

| Feature | Action Scheduler | WP Queue |
|---------|-----------------|----------|
| Database | 2 custom tables | wp_options |
| API | 20+ functions | 1 facade |
| PHP version | 7.4+ | 8.3+ |
| Registration | Manual | Attributes |
| Retry logic | Complex | `#[Retries(3)]` |
| UI | Built-in | Built-in |
| File count | 50+ | ~15 |

## Testing

WP Queue uses a CI-first testing approach with comprehensive test coverage.

### Unit Tests (Local)

Fast isolated tests without WordPress environment:

```bash
composer test:unit
```

**Results:** 69 tests, 130 assertions ✅

### E2E Tests (GitHub Actions)

Integration tests with real WordPress run automatically in CI:

- ✅ WordPress latest (6.7+) + PHP 8.3
- ✅ WordPress 6.7 + PHP 8.3

E2E tests run on every push to `main`/`develop` branches and in pull requests.

### Code Quality

```bash
# Code style check
composer lint

# Run all tests
composer test
```

**Note:** Code coverage requires PCOV or Xdebug extension. In Docker environments, use:

```bash
docker exec wp_site-php composer lint
docker exec wp_site-php composer test:unit
```

See: [tests/README.md](tests/README.md)

## Example Plugin

See a complete working example: **[wp-queue-example-plugin](https://github.com/rwsite/wp-queue-example-plugin)**

The example plugin demonstrates:

- ✅ Creating custom jobs with PHP 8 attributes
- ✅ Job scheduling with different intervals
- ✅ Error handling and retries
- ✅ Chain and batch processing
- ✅ Queue management and monitoring
- ✅ REST API integration
- ✅ WP-CLI commands

Perfect for learning how to integrate WP Queue into your WordPress plugins!

## License

GPL-2.0-or-later
