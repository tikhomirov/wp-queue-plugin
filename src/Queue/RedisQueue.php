<?php

declare(strict_types=1);

namespace WPQueue\Queue;

use WPQueue\Contracts\InspectableQueue;
use WPQueue\Contracts\JobInterface;
use WPQueue\Contracts\QueueInterface;
use WPQueue\Contracts\TransferableQueue;
use WPQueue\Queue\Redis\RedisClientFactory;
use WPQueue\Queue\Redis\RedisClientInterface;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Redis-based queue implementation.
 *
 * Compatible with redis-cache plugin constants:
 * - WP_REDIS_HOST (default: 127.0.0.1)
 * - WP_REDIS_PORT (default: 6379)
 * - WP_REDIS_PASSWORD (optional)
 * - WP_REDIS_DATABASE (default: 0)
 * - WP_REDIS_PREFIX (default: wp_queue_)
 * - WP_REDIS_SCHEME (tcp/unix/tls)
 * - WP_REDIS_PATH (for unix socket)
 * - WP_REDIS_TIMEOUT (default: 1)
 * - WP_REDIS_READ_TIMEOUT (default: 1)
 * - WP_REDIS_CLIENT (phpredis/predis)
 *
 * Supports both phpredis extension and Predis library (via redis-cache plugin).
 *
 * Layout per queue (all changes go through Lua scripts, so they are atomic):
 * - q:{queue}:data      hash  job id => JSON (payload, attempts, available_at)
 * - q:{queue}:ready     list  job ids ready to run
 * - q:{queue}:delayed   zset  job id => available_at
 * - q:{queue}:reserved  zset  job id => reserved_at (claimed by a worker)
 * - queues              set   known queue names
 * - index               hash  job id => queue name
 *
 * pop() moves due delayed jobs and stale reservations back to the ready list,
 * then LPOPs one id and reserves it in the same script, so every job is handed
 * to exactly one worker.
 *
 * @see https://github.com/rhubarbgroup/redis-cache
 */
class RedisQueue implements InspectableQueue, QueueInterface, TransferableQueue
{
    protected const PREFIX = 'wp_queue:';

    /**
     * Seconds after which a reservation is considered abandoned.
     */
    public const STALE_THRESHOLD = 300;

    /**
     * @var array<string, true> Queues whose legacy (<= 1.3.0) keys were imported.
     */
    protected array $legacyChecked = [];

    protected ?RedisClientInterface $client = null;

    /**
     * @var array{
     *     host: string,
     *     port: int,
     *     password: string|array|null,
     *     database: int,
     *     prefix: string,
     *     scheme: string,
     *     path: string|null,
     *     timeout: float,
     *     read_timeout: float
     * }
     */
    protected array $config;

    /**
     * @param  array<string, mixed>  $config  Optional config override
     */
    public function __construct(array $config = [])
    {
        $this->config = $this->resolveConfig($config);
        $this->client = RedisClientFactory::create($this->config);
    }

    /**
     * Resolve configuration from WP constants and overrides.
     *
     * @param  array<string, mixed>  $config
     * @return array{host: string, port: int, password: string|array|null, database: int, prefix: string, scheme: string, path: string|null, timeout: float, read_timeout: float}
     */
    protected function resolveConfig(array $config): array
    {
        return [
            'host' => $config['host'] ?? (defined('WP_REDIS_HOST') ? WP_REDIS_HOST : '127.0.0.1'),
            'port' => (int) ($config['port'] ?? (defined('WP_REDIS_PORT') ? WP_REDIS_PORT : 6379)),
            'password' => $config['password'] ?? (defined('WP_REDIS_PASSWORD') ? WP_REDIS_PASSWORD : null),
            'database' => (int) ($config['database'] ?? (defined('WP_REDIS_DATABASE') ? WP_REDIS_DATABASE : 0)),
            'prefix' => $config['prefix'] ?? $this->resolvePrefix(),
            'scheme' => $config['scheme'] ?? (defined('WP_REDIS_SCHEME') ? WP_REDIS_SCHEME : 'tcp'),
            'path' => $config['path'] ?? (defined('WP_REDIS_PATH') ? WP_REDIS_PATH : null),
            'timeout' => (float) ($config['timeout'] ?? (defined('WP_REDIS_TIMEOUT') ? WP_REDIS_TIMEOUT : 1)),
            'read_timeout' => (float) ($config['read_timeout'] ?? (defined('WP_REDIS_READ_TIMEOUT') ? WP_REDIS_READ_TIMEOUT : 1)),
        ];
    }

    /**
     * Resolve prefix from WP constants.
     */
    protected function resolvePrefix(): string
    {
        if (defined('WP_REDIS_PREFIX')) {
            return WP_REDIS_PREFIX.self::PREFIX;
        }

        if (defined('WP_CACHE_KEY_SALT')) {
            return WP_CACHE_KEY_SALT.self::PREFIX;
        }

        return self::PREFIX;
    }

    /**
     * Get Redis client.
     *
     * @throws \RuntimeException If no Redis client available or connection fails
     */
    protected function connection(): RedisClientInterface
    {
        if ($this->client === null) {
            $this->client = RedisClientFactory::create($this->config);
        }

        if (! $this->client->isConnected()) {
            $this->client->connect();
        }

        return $this->client;
    }

    /**
     * Check if Redis is available.
     */
    public static function isAvailable(): bool
    {
        // Check if any Redis client is available
        if (! RedisClientFactory::isAvailable()) {
            return false;
        }

        // Check if Redis is disabled
        if (defined('WP_REDIS_DISABLED') && WP_REDIS_DISABLED) {
            return false;
        }

        // Try to connect
        try {
            $queue = new self();

            return $queue->connection()->ping();
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Get Redis connection info for debugging.
     *
     * @return array<string, mixed>
     */
    public function getConnectionInfo(): array
    {
        return [
            'host' => $this->config['host'],
            'port' => $this->config['port'],
            'database' => $this->config['database'],
            'prefix' => $this->config['prefix'],
            'scheme' => $this->config['scheme'],
            'connected' => $this->client?->isConnected() ?? false,
            'client_type' => $this->client?->getClientType() ?? 'none',
            'phpredis' => extension_loaded('redis') ? phpversion('redis') : 'not installed',
            'predis' => RedisClientFactory::isPredisAvailable() ? 'available' : 'not installed',
        ];
    }

    public function push(JobInterface $job): string
    {
        $queue = $job->getQueue() ?: 'default';
        $availableAt = time() + $job->getDelay();

        $this->connection()->evalScript(self::SCRIPT_PUSH, $this->keys($queue), [
            $job->getId(),
            $this->encode($job, $availableAt),
            $queue,
            $availableAt,
            $job->getDelay() > 0 ? 1 : 0,
        ]);

        return $job->getId();
    }

    public function later(int $delay, JobInterface $job): string
    {
        $job->delay($delay);

        return $this->push($job);
    }

    public function pop(string $queue = 'default'): ?JobInterface
    {
        $this->importLegacy($queue);

        $now = time();

        while (true) {
            $result = $this->connection()->evalScript(self::SCRIPT_POP, $this->keys($queue), [
                $now,
                $now - self::STALE_THRESHOLD,
            ]);

            if (! is_array($result) || count($result) < 2) {
                return null;
            }

            $job = $this->decode((string) $result[1]);

            if ($job instanceof JobInterface) {
                return $job;
            }

            error_log(sprintf('WP Queue: Failed to unserialize Redis job %s, removing it', (string) $result[0]));
            $this->delete((string) $result[0]);
        }
    }

    public function delete(string $jobId): bool
    {
        $queue = $this->queueOf($jobId);

        if ($queue === null) {
            return false;
        }

        return (int) $this->connection()->evalScript(self::SCRIPT_DELETE, $this->keys($queue), [$jobId]) > 0;
    }

    public function release(JobInterface $job, int $delay = 0): void
    {
        $queue = $job->getQueue() ?: 'default';
        $availableAt = time() + $delay;

        $this->connection()->evalScript(self::SCRIPT_RELEASE, $this->keys($queue), [
            $job->getId(),
            $this->encode($job, $availableAt),
            $queue,
            $availableAt,
            $delay > 0 ? 1 : 0,
        ]);
    }

    public function size(string $queue = 'default'): int
    {
        $this->importLegacy($queue);

        return (int) $this->connection()->evalScript("return redis.call('HLEN', KEYS[4])", $this->keys($queue));
    }

    public function clear(string $queue = 'default'): int
    {
        return (int) $this->connection()->evalScript(self::SCRIPT_CLEAR, $this->keys($queue));
    }

    public function isEmpty(string $queue = 'default'): bool
    {
        return $this->size($queue) === 0;
    }

    public function queues(): array
    {
        return $this->connection()->sMembers('queues');
    }

    public function jobs(string $queue): array
    {
        $raw = $this->connection()->evalScript(self::SCRIPT_JOBS, $this->keys($queue));
        $jobs = [];

        if (! is_array($raw)) {
            return $jobs;
        }

        for ($i = 0; $i + 2 < count($raw); $i += 3) {
            $data = json_decode((string) $raw[$i + 1], true);

            if (! is_array($data)) {
                continue;
            }

            $reservedAt = $raw[$i + 2];
            $jobs[(string) $raw[$i]] = [
                'payload' => (string) base64_decode((string) ($data['payload'] ?? ''), true),
                'available_at' => (int) ($data['available_at'] ?? 0),
                'reserved_at' => ($reservedAt === false || $reservedAt === null || $reservedAt === '') ? null : (int) $reservedAt,
                'attempts' => (int) ($data['attempts'] ?? 0),
            ];
        }

        uasort($jobs, static fn (array $a, array $b): int => $a['available_at'] <=> $b['available_at']);

        return $jobs;
    }

    public function reservedCount(string $queue): int
    {
        return (int) $this->connection()->evalScript(
            "return redis.call('ZCOUNT', KEYS[3], ARGV[1], '+inf')",
            $this->keys($queue),
            [time() - self::STALE_THRESHOLD],
        );
    }

    public function takePending(string $queue, int $limit = 500): array
    {
        $this->importLegacy($queue);

        $raw = $this->connection()->evalScript(self::SCRIPT_TAKE, $this->keys($queue), [
            time() - self::STALE_THRESHOLD,
            $limit,
        ]);

        $taken = [];

        if (! is_array($raw)) {
            return $taken;
        }

        for ($i = 0; $i + 1 < count($raw); $i += 2) {
            $data = json_decode((string) $raw[$i + 1], true);
            $job = is_array($data) ? $this->decode((string) $raw[$i + 1]) : null;

            if ($job instanceof JobInterface) {
                $taken[] = ['job' => $job, 'available_at' => (int) ($data['available_at'] ?? time())];
            } else {
                error_log(sprintf('WP Queue: Dropped unreadable Redis job %s while moving queue %s', (string) $raw[$i], $queue));
            }
        }

        return $taken;
    }

    /**
     * Get queue statistics.
     *
     * @return array{pending: int, delayed: int, reserved: int, total: int}
     */
    public function stats(string $queue = 'default'): array
    {
        $raw = $this->connection()->evalScript(
            "return {redis.call('LLEN', KEYS[1]), redis.call('ZCARD', KEYS[2]), redis.call('ZCARD', KEYS[3]), redis.call('HLEN', KEYS[4])}",
            $this->keys($queue),
        );
        $raw = is_array($raw) ? array_map('intval', $raw) : [0, 0, 0, 0];

        return [
            'pending' => $raw[0] ?? 0,
            'delayed' => $raw[1] ?? 0,
            'reserved' => $raw[2] ?? 0,
            'total' => $raw[3] ?? 0,
        ];
    }

    /**
     * Get all queue names.
     *
     * @return string[]
     */
    protected function getAllQueues(): array
    {
        $queues = $this->queues();

        return $queues ?: ['default'];
    }

    /**
     * Keys of one queue in the order the scripts expect.
     *
     * @return list<string>
     */
    protected function keys(string $queue): array
    {
        return [
            'q:'.$queue.':ready',
            'q:'.$queue.':delayed',
            'q:'.$queue.':reserved',
            'q:'.$queue.':data',
            'queues',
            'index',
        ];
    }

    protected function queueOf(string $jobId): ?string
    {
        $queue = $this->connection()->evalScript("return redis.call('HGET', KEYS[1], ARGV[1])", ['index'], [$jobId]);

        return is_string($queue) && $queue !== '' ? $queue : null;
    }

    protected function encode(JobInterface $job, int $availableAt): string
    {
        return (string) json_encode([
            'id' => $job->getId(),
            // Serialized objects contain NUL bytes and may hold non-UTF-8 data.
            'payload' => base64_encode(serialize($job)),
            'attempts' => $job->getAttempts(),
            'available_at' => $availableAt,
            'created_at' => time(),
        ]);
    }

    protected function decode(string $json): ?JobInterface
    {
        $data = json_decode($json, true);

        if (! is_array($data) || ! isset($data['payload'])) {
            return null;
        }

        $raw = base64_decode((string) $data['payload'], true);

        if ($raw === false) {
            return null;
        }

        try {
            $job = unserialize($raw);
        } catch (\Throwable) {
            return null;
        }

        return $job instanceof JobInterface ? $job : null;
    }

    /**
     * Move jobs stored by WP Queue <= 1.3.0 (JSON in jobs:{queue} lists) to the
     * current layout, once per process and queue.
     */
    protected function importLegacy(string $queue): void
    {
        if (isset($this->legacyChecked[$queue])) {
            return;
        }

        $this->legacyChecked[$queue] = true;
        $client = $this->connection();

        $legacy = [];

        while (($item = $client->lPop('jobs:'.$queue)) !== null) {
            $legacy[] = $item;
        }

        foreach ($client->zRange('jobs:'.$queue.':delayed', 0, -1) as $item) {
            if ($client->zRem('jobs:'.$queue.':delayed', $item) === 1) {
                $legacy[] = $item;
            }
        }

        foreach ($legacy as $item) {
            $data = json_decode($item, true);

            if (! is_array($data) || ! isset($data['payload'])) {
                continue;
            }

            try {
                $job = unserialize((string) $data['payload']);
            } catch (\Throwable) {
                $job = null;
            }

            if ($job instanceof JobInterface) {
                $job->delay(max(0, (int) ($data['available_at'] ?? 0) - time()));
                $this->push($job);
            }
        }
    }

    /**
     * Close Redis connection.
     */
    public function disconnect(): void
    {
        $this->client?->close();
    }

    public function __destruct()
    {
        $this->disconnect();
    }

    // KEYS: ready, delayed, reserved, data, queues, index
    // ARGV: id, json, queue, available_at, delayed(0|1)
    protected const SCRIPT_PUSH = <<<'LUA'
redis.call('HSET', KEYS[4], ARGV[1], ARGV[2])
redis.call('HSET', KEYS[6], ARGV[1], ARGV[3])
redis.call('SADD', KEYS[5], ARGV[3])
if ARGV[5] == '1' then
  redis.call('ZADD', KEYS[2], ARGV[4], ARGV[1])
else
  redis.call('RPUSH', KEYS[1], ARGV[1])
end
return 1
LUA;

    // ARGV: now, stale_before
    protected const SCRIPT_POP = <<<'LUA'
local now = tonumber(ARGV[1])
local due = redis.call('ZRANGEBYSCORE', KEYS[2], '-inf', now, 'LIMIT', 0, 100)
for _, id in ipairs(due) do
  redis.call('ZREM', KEYS[2], id)
  redis.call('RPUSH', KEYS[1], id)
end
local stale = redis.call('ZRANGEBYSCORE', KEYS[3], '-inf', '(' .. ARGV[2], 'LIMIT', 0, 100)
for _, id in ipairs(stale) do
  redis.call('ZREM', KEYS[3], id)
  redis.call('RPUSH', KEYS[1], id)
end
while true do
  local id = redis.call('LPOP', KEYS[1])
  if not id then
    return false
  end
  local data = redis.call('HGET', KEYS[4], id)
  if data then
    redis.call('ZADD', KEYS[3], now, id)
    return {id, data}
  end
end
LUA;

    // ARGV: id
    protected const SCRIPT_DELETE = <<<'LUA'
local removed = redis.call('HDEL', KEYS[4], ARGV[1])
redis.call('ZREM', KEYS[3], ARGV[1])
redis.call('ZREM', KEYS[2], ARGV[1])
redis.call('LREM', KEYS[1], 0, ARGV[1])
redis.call('HDEL', KEYS[6], ARGV[1])
return removed
LUA;

    // ARGV: id, json, queue, available_at, delayed(0|1)
    protected const SCRIPT_RELEASE = <<<'LUA'
redis.call('ZREM', KEYS[3], ARGV[1])
redis.call('LREM', KEYS[1], 0, ARGV[1])
redis.call('ZREM', KEYS[2], ARGV[1])
redis.call('HSET', KEYS[4], ARGV[1], ARGV[2])
redis.call('HSET', KEYS[6], ARGV[1], ARGV[3])
redis.call('SADD', KEYS[5], ARGV[3])
if ARGV[5] == '1' then
  redis.call('ZADD', KEYS[2], ARGV[4], ARGV[1])
else
  redis.call('RPUSH', KEYS[1], ARGV[1])
end
return 1
LUA;

    protected const SCRIPT_CLEAR = <<<'LUA'
local ids = redis.call('HKEYS', KEYS[4])
for _, id in ipairs(ids) do
  redis.call('HDEL', KEYS[6], id)
end
redis.call('DEL', KEYS[1], KEYS[2], KEYS[3], KEYS[4])
return #ids
LUA;

    protected const SCRIPT_JOBS = <<<'LUA'
local all = redis.call('HGETALL', KEYS[4])
local out = {}
for i = 1, #all, 2 do
  table.insert(out, all[i])
  table.insert(out, all[i + 1])
  local score = redis.call('ZSCORE', KEYS[3], all[i])
  table.insert(out, score or '')
end
return out
LUA;

    // ARGV: stale_before, limit
    protected const SCRIPT_TAKE = <<<'LUA'
local limit = tonumber(ARGV[2])
local out = {}
local ids = {}
for _, id in ipairs(redis.call('LRANGE', KEYS[1], 0, limit - 1)) do table.insert(ids, id) end
for _, id in ipairs(redis.call('ZRANGE', KEYS[2], 0, limit - 1)) do table.insert(ids, id) end
for _, id in ipairs(redis.call('ZRANGEBYSCORE', KEYS[3], '-inf', '(' .. ARGV[1], 'LIMIT', 0, limit)) do table.insert(ids, id) end
for _, id in ipairs(ids) do
  if #out >= limit * 2 then break end
  local data = redis.call('HGET', KEYS[4], id)
  redis.call('LREM', KEYS[1], 0, id)
  redis.call('ZREM', KEYS[2], id)
  redis.call('ZREM', KEYS[3], id)
  redis.call('HDEL', KEYS[4], id)
  redis.call('HDEL', KEYS[6], id)
  if data then
    table.insert(out, id)
    table.insert(out, data)
  end
end
return out
LUA;
}
