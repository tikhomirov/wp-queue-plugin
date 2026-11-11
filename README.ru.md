<p align="center">
  <img src="assets/images/logo.svg" alt="WP Queue Logo" width="150" height="150">
</p>

<h1 align="center">WP Queue</h1>

<p align="center">
  <strong>Менеджер очередей для WordPress, вдохновленный Laravel Horizon</strong><br>
  Простая, мощная и современная обработка фоновых задач.
</p>

<p align="center">
  <a href="https://packagist.org/packages/rwsite/wp-queue"><img src="https://img.shields.io/packagist/v/rwsite/wp-queue.svg?style=flat-square" alt="Latest Version"></a>
  <a href="https://packagist.org/packages/rwsite/wp-queue"><img src="https://img.shields.io/packagist/php-v/rwsite/wp-queue.svg?style=flat-square" alt="PHP Version"></a>
  <a href="https://github.com/rwsite/wp-queue/actions"><img src="https://img.shields.io/github/actions/workflow/status/rwsite/wp-queue/tests.yml?branch=main&style=flat-square" alt="Build Status"></a>
  <a href="https://codecov.io/gh/rwsite/wp-queue"><img src="https://img.shields.io/codecov/c/github/rwsite/wp-queue?style=flat-square" alt="Code Coverage"></a>
  <a href="LICENSE"><img src="https://img.shields.io/badge/license-GPL--2.0--or--later-blue.svg?style=flat-square" alt="License"></a>
</p>

<p align="center">
  <a href="#возможности">Возможности</a> •
  <a href="#установка">Установка</a> •
  <a href="#быстрый-старт">Быстрый старт</a> •
  <a href="#админ-панель">Админ панель</a> •
  <a href="#rest-api">REST API</a> •
  <a href="#тестирование">Тестирование</a> •
  <a href="README.md">🇬🇧 English</a>
</p>

---

## Возможности

- 🚀 **API в стиле Laravel** — Чистый, fluent API для отправки задач с атрибутами PHP 8
- 📦 **Несколько драйверов** — Database, Redis, Memcached; обработчик (daemon, loopback, WP-Cron, sync) выбирается в админке или автоматически
- 🔄 **Автоматические повторы** — Экспоненциальная задержка для неудачных задач
- ⏰ **Планирование** — Cron-подобное планирование задач
- 👁️ **Монитор WP-Cron** — Просмотр, запуск, пауза, возобновление, редактирование cron событий
- 📊 **Панель управления** — Красивый интерфейс админ-панели со статистикой
- 🔌 **REST API** — Полный API для интеграций
- 💻 **WP-CLI** — Полный интерфейс командной строки
- 🌍 **i18n Ready** — Включены переводы (русский язык)

## Требования

- PHP 8.3+
- WordPress 6.0+

## Установка

1. Клонируйте или загрузите в `wp-content/plugins/wp-queue`
2. Запустите `composer install`
3. Активируйте плагин

## Быстрый старт

### Создание задачи

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
            // Логика импорта
        }
    }
    
    public function failed(\Throwable $e): void
    {
        // Обработка ошибки
        error_log('Импорт не удался: ' . $e->getMessage());
    }
}
```

### Отправка задач

```php
use WPQueue\WPQueue;

// Отправить в очередь
WPQueue::dispatch(new ImportProductsJob([1, 2, 3]));

// С задержкой (секунды)
WPQueue::dispatch(new ImportProductsJob($ids))->delay(60);

// В конкретную очередь
WPQueue::dispatch(new ImportProductsJob($ids))->onQueue('high-priority');

// Синхронное выполнение (обойти очередь)
WPQueue::dispatchSync(new ImportProductsJob($ids));

// Цепочка задач
WPQueue::chain([
    new FetchProductsJob(),
    new ImportProductsJob($ids),
    new NotifyAdminJob(),
])->dispatch();

// Пакетная обработка
WPQueue::batch([
    new ImportProductJob($id1),
    new ImportProductJob($id2),
    new ImportProductJob($id3),
])->dispatch();
```

### Планирование задач

```php
// В вашем плагине
add_action('wp_queue_schedule', function ($scheduler) {
    // Использование атрибутов (автоматически)
    $scheduler->job(ImportProductsJob::class);
    
    // Ручное планирование
    $scheduler->job(CleanupJob::class)->daily();
    
    // Пользовательский интервал
    $scheduler->job(CheckStopListJob::class)->everyMinutes(15);
    
    // С условием
    $scheduler->job(SyncJob::class)
        ->hourly()
        ->when(fn() => get_option('sync_enabled'));
    
    // Из настроек
    $scheduler->job(BackupJob::class)
        ->interval(get_option('backup_interval', 'daily'));

    // Одноразовое выполнение (в указанное время)
    $scheduler->job(OneTimeJob::class)->at(time() + 3600);
});
```

## Атрибуты PHP 8

### `#[Schedule]`

```php
#[Schedule('hourly')]                    // Фиксированный интервал
#[Schedule('daily', setting: 'my_opt')]  // Из wp_options
```

Доступные интервалы: `min`, `5min`, `10min`, `15min`, `30min`, `hourly`, `2hourly`, `3hourly`, `6hourly`, `8hourly`, `12hourly`, `daily`, `twicedaily`, `weekly`

### `#[Queue]`

```php
#[Queue('default')]    // Имя очереди
#[Queue('high')]       // Приоритетная очередь
```

### `#[Timeout]`

```php
#[Timeout(60)]   // 60 секунд
#[Timeout(300)]  // 5 минут
```

### `#[Retries]`

```php
#[Retries(3)]   // Максимум 3 попытки
#[Retries(5)]   // Максимум 5 попыток
```

### `#[UniqueJob]`

```php
#[UniqueJob]           // Предотвратить дублирование задач
#[UniqueJob(key: 'custom_key')]
```

## События (Hooks)

```php
// Перед выполнением задачи
add_action('wp_queue_job_processing', function($event) {
    // $event->job, $event->queue
});

// После успешного выполнения
add_action('wp_queue_job_processed', function($event) {
    // $event->job, $event->queue
});

// При ошибке
add_action('wp_queue_job_failed', function($event) {
    // $event->job, $event->queue, $event->exception
});

// При повторе
add_action('wp_queue_job_retrying', function($event) {
    // $event->job, $event->queue, $event->attempt, $event->exception
});
```

## Управление очередями

```php
use WPQueue\WPQueue;

// Проверка статуса
WPQueue::isProcessing('default');   // bool
WPQueue::isPaused('default');       // bool
WPQueue::queueSize('default');      // int

// Управление
WPQueue::pause('default');
WPQueue::resume('default');
WPQueue::cancel('default');   // Очистить + пауза
WPQueue::clear('default');    // Удалить все задачи

// Логи
WPQueue::logs()->recent(100);
WPQueue::logs()->failed();
WPQueue::logs()->forJob(ImportProductsJob::class);
WPQueue::logs()->metrics();
WPQueue::logs()->clearOld(7);  // Очистить логи старше 7 дней
```

## Админ панель

Доступна через **WP Admin → WP Queue**

### Вкладки

| Вкладка | Описание |
|---------|---------|
| **Панель управления** | Статистика (ожидание, выполнение, завершено, ошибки), управление очередями |
| **Запланированные задачи** | Задачи WP Queue с кнопкой "Запустить сейчас" |
| **Логи** | История выполнения с фильтрацией |
| **WP-Cron** | Мониторинг всех событий WordPress cron |
| **Система** | Статус окружения и проверка здоровья |

### Монитор WP-Cron

Просмотр и управление всеми событиями WordPress cron:

- Фильтрация по источнику (WordPress, WooCommerce, Плагины, WP Queue)
- Просмотр просроченных событий
- Ручной запуск событий
- Удаление запланированных событий
- Просмотр зарегистрированных расписаний

### Статус системы

Проверка здоровья и информация об окружении:

- Версии PHP/WordPress
- Лимит памяти и использование
- Статус WP-Cron (отключен/альтернативный)
- Проверка loopback запросов
- Статистика Action Scheduler (если установлен)
- Информация о часовом поясе и времени

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

# Cron & Система
GET    /wp-json/wp-queue/v1/cron
POST   /wp-json/wp-queue/v1/cron/run
POST   /wp-json/wp-queue/v1/cron/delete
GET    /wp-json/wp-queue/v1/system
```

## Драйверы и обработчики очередей

Две независимые настройки, обе выбираются в **Очереди → Драйверы** и **Система → Состояние** (или `wp queue config`):

* **Драйвер хранения** — где лежат задачи.
* **Обработчик** — кто их выполняет. Активен ровно один; остальные не регистрируют хуки и не шлют запросы.

| Драйвер | Описание | Требования |
|---------|---------|-----------|
| `database` | Таблица `{prefix}queue_jobs`, атомарный захват (по умолчанию) | Нет |
| `redis` | Lua-скрипты: атомарный захват, отложенные/зарезервированные, восстановление после падения воркера | phpredis или Predis (Redis Object Cache) |
| `memcached` | Не рекомендуется: задачи нельзя перечислить и перенести | расширение memcached |

| Обработчик | Как запускаются задачи |
|------------|------------------------|
| `auto` (по умолчанию) | Самый быстрый доступный: `daemon`, если свежий heartbeat (< 10 мин), иначе `loopback`, `wp_cron`, `sync`. В админке видно фактический обработчик и причину |
| `daemon` | Системный cron или демон запускает `wp queue work --all` (строка crontab и heartbeat видны в админке) |
| `loopback` | Неблокирующий POST в `admin-ajax.php` после dispatch (как Action Scheduler) |
| `wp_cron` | Событие WP-Cron `wp_queue_process` |
| `sync` | В конце запроса, который поставил задачу |

Кнопка **Сделать активным** переносит ожидающие задачи (готовые и отложенные) в новый бэкенд под глобальной блокировкой воркера; пока задачи выполняются, переключение отклоняется.

### Конфигурация

Приоритет: константа в `wp-config.php` → опция плагина → значение по умолчанию.

```php
define('WP_QUEUE_DRIVER', 'redis');   // database | redis | memcached
define('WP_QUEUE_RUNNER', 'daemon');  // auto | daemon | loopback | wp_cron | sync
// Старые константы работают: WP_QUEUE_RUNTIME_MODE ('daemon' | 'cron_loopback'), WP_QUEUE_LOOPBACK (false — выключить loopback)

// Настройки Redis (совместимо с плагином redis-cache)
define('WP_REDIS_HOST', 'redis');      // или '127.0.0.1'
define('WP_REDIS_PORT', '6379');
define('WP_REDIS_PREFIX', 'mysite_');  // опционально
define('WP_REDIS_PASSWORD', 'secret'); // опционально
define('WP_REDIS_DATABASE', 0);        // опционально, 0-15

// Настройки Memcached
define('WP_MEMCACHED_HOST', '127.0.0.1');
define('WP_MEMCACHED_PORT', 11211);
```

Константа в любом месте `wp-config.php` блокирует настройку в админке («задано в wp-config.php»). Константы внутри собственного блока плагина остаются редактируемыми из админки:

```php
// BEGIN WP Queue
// Managed by WP Queue (Tools > Queues). Edit in the admin or here.
define('WP_QUEUE_DRIVER', 'redis');
// END WP Queue
```

Плагин переписывает только этот блок: временный файл + атомарный rename, владелец и права сохраняются, бэкап, проверка синтаксиса PHP и загрузки WordPress по HTTP; при ошибке бэкап восстанавливается. Если файл недоступен для записи, админка показывает блок для ручной вставки. Нужны права `manage_options`, во всех формах nonce.

```bash
wp queue config                                   # драйвер, обработчик, причина, heartbeat
wp queue config --driver=redis --store=wp-config  # перенести задачи, сохранить в блоке WP Queue
wp queue config --runner=daemon                   # сохранить в опции плагина
```

Подробнее: [docs/runners-and-drivers.md](docs/runners-and-drivers.md).

### Программная конфигурация

```php
use WPQueue\WPQueue;
use WPQueue\Contracts\QueueInterface;

// Установка драйвера программно
WPQueue::manager()->setDefaultDriver('redis');

// Проверка доступных драйверов
$drivers = WPQueue::manager()->getAvailableDrivers();
// ['database' => ['available' => true, 'info' => '...'], ...]

// Проверка доступности конкретного драйвера
if (WPQueue::manager()->isDriverAvailable('redis')) {
    WPQueue::manager()->setDefaultDriver('redis');
}

// Регистрация пользовательского драйвера
WPQueue::manager()->extend('sqs', function() {
    return new SqsQueue(['region' => 'us-east-1']);
});
```

### Redis с плагином redis-cache

Если вы используете плагин [Redis Object Cache](https://github.com/rhubarbgroup/redis-cache), WP Queue автоматически использует те же настройки подключения Redis. Дополнительная конфигурация не требуется!

```php
// Эти константы из redis-cache автоматически используются:
// WP_REDIS_HOST, WP_REDIS_PORT, WP_REDIS_PASSWORD, WP_REDIS_DATABASE
// WP_REDIS_PREFIX, WP_REDIS_SCHEME, WP_REDIS_PATH, WP_REDIS_TIMEOUT
```

## Миграция с Action Scheduler

### До (Action Scheduler)

```php
as_schedule_recurring_action(time(), HOUR_IN_SECONDS, 'my_hourly_task');

add_action('my_hourly_task', function() {
    // код задачи
});
```

### После (WP Queue)

```php
#[Schedule('hourly')]
class MyHourlyTask extends Job
{
    public function handle(): void
    {
        // код задачи
    }
}

// Регистрация
add_action('wp_queue_schedule', fn($s) => $s->job(MyHourlyTask::class));
```

## Сравнение с Action Scheduler

| Функция | Action Scheduler | WP Queue |
|---------|-----------------|----------|
| База данных | 2 пользовательские таблицы | wp_options |
| API | 20+ функций | 1 фасад |
| Версия PHP | 7.4+ | 8.3+ |
| Регистрация | Ручная | Атрибуты |
| Логика повторов | Сложная | `#[Retries(3)]` |
| Интерфейс | Встроенный | Встроенный |
| Количество файлов | 50+ | ~15 |

## Тестирование

WP Queue использует CI-first подход к тестированию с полным покрытием.

### Unit тесты (локально)

Быстрые изолированные тесты без окружения WordPress:

```bash
composer test:unit
```

**Результаты:** 69 тестов, 130 утверждений ✅

### E2E тесты (GitHub Actions)

Интеграционные тесты с реальным WordPress запускаются автоматически в CI:

- ✅ WordPress latest (6.7+) + PHP 8.3
- ✅ WordPress 6.7 + PHP 8.3

E2E тесты запускаются при каждом push в ветки `main`/`develop` и в pull requests.

### Проверка качества кода

```bash
# Проверка стиля кода
composer lint

# Запуск всех тестов
composer test
```

**Примечание:** Покрытие кода требует расширения PCOV или Xdebug. В Docker окружении используйте:

```bash
docker exec wp_site-php composer lint
docker exec wp_site-php composer test:unit
```

Подробнее: [tests/README.md](tests/README.md)

## Пример плагина

Смотрите полный рабочий пример: **[wp-queue-example-plugin](https://github.com/rwsite/wp-queue-example-plugin)**

Пример плагина демонстрирует:

- ✅ Создание пользовательских задач с атрибутами PHP 8
- ✅ Планирование задач с разными интервалами
- ✅ Обработку ошибок и повторы
- ✅ Цепочку и пакетную обработку
- ✅ Управление очередями и мониторинг
- ✅ Интеграцию REST API
- ✅ Команды WP-CLI

Идеально подходит для изучения того, как интегрировать WP Queue в ваши плагины WordPress!

## Лицензия

GPL-2.0-or-later
