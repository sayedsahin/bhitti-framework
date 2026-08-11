<?php

declare(strict_types=1);

namespace Bhitti\Connector;

use Redis;
use RedisException;
use RuntimeException;

/**
 * Request-local Redis client registry backed by optional PhpRedis persistent sockets.
 *
 * Design rules:
 * - Connections are lazy: no socket is opened until connection() is first requested.
 * - A named profile owns a fixed endpoint/database for its whole lifetime.
 * - Services may share one profile (fast default) or map to separate profiles.
 * - Key prefixes are service concerns; this manager never mutates Redis::OPT_PREFIX.
 * - Managed clients must not call SELECT after creation. Use another named profile.
 */
final class RedisConnectionManager
{
    /** @var array<string, Redis> */
    private static array $connections = [];

    /** @var array<string, array<string, mixed>> */
    private static array $profiles = [];

    public static function connection(?string $name = null): Redis
    {
        $name = self::normalizeName(
            $name ?? (string) config('database.redis.default', 'default')
        );

        if (isset(self::$connections[$name])) {
            return self::$connections[$name];
        }

        if (!class_exists(Redis::class)) {
            throw new RuntimeException(
                'Redis connection requires the PHP Redis extension.'
            );
        }

        $profile = self::profile($name);

        return self::$connections[$name] = self::create($name, $profile);
    }

    public static function configured(string $name): bool
    {
        $connections = (array) config('database.redis.connections', []);

        return isset($connections[$name]) && is_array($connections[$name]);
    }

    /**
     * Forget only the request-local object reference.
     * Persistent sockets intentionally remain managed by PhpRedis/FPM.
     */
    public static function forget(string $name): void
    {
        $name = self::normalizeName($name);

        if (!isset(self::$connections[$name])) {
            unset(self::$profiles[$name]);
            return;
        }

        $profile = self::$profiles[$name] ?? self::profile($name);
        $persistent = (bool) ($profile['persistent'] ?? true);

        if (!$persistent) {
            try {
                self::$connections[$name]->close();
            } catch (RedisException $exception) {
                // Connection is already unusable/closed; dropping the reference is enough.
            }
        }

        unset(self::$connections[$name], self::$profiles[$name]);
    }

    /**
     * Primarily useful for tests or long-running non-FPM runtimes.
     */
    public static function reset(): void
    {
        foreach (array_keys(self::$connections) as $name) {
            self::forget($name);
        }

        self::$profiles = [];
    }

    /** @return array<string, mixed> */
    private static function profile(string $name): array
    {
        if (isset(self::$profiles[$name])) {
            return self::$profiles[$name];
        }

        $connections = (array) config('database.redis.connections', []);
        $profile = $connections[$name] ?? null;

        if (!is_array($profile)) {
            throw new RuntimeException(
                "Redis connection profile [{$name}] is not configured."
            );
        }

        $host = trim((string) ($profile['host'] ?? ''));
        $port = (int) ($profile['port'] ?? 6379);
        $database = (int) ($profile['database'] ?? 0);
        $timeout = (float) ($profile['timeout'] ?? 2.0);
        $readTimeout = (float) ($profile['read_timeout'] ?? 2.0);

        if ($host === '') {
            throw new RuntimeException(
                "Redis connection profile [{$name}] requires a host."
            );
        }

        if ($port < 0 || $port > 65535) {
            throw new RuntimeException(
                "Redis connection profile [{$name}] has an invalid port."
            );
        }

        if ($database < 0) {
            throw new RuntimeException(
                "Redis connection profile [{$name}] has an invalid database."
            );
        }

        if ($timeout < 0.0 || $readTimeout < -1.0) {
            throw new RuntimeException(
                "Redis connection profile [{$name}] has invalid timeout values."
            );
        }

        $profile['host'] = $host;
        $profile['port'] = $port;
        $profile['database'] = $database;
        $profile['timeout'] = $timeout;
        $profile['read_timeout'] = $readTimeout;
        $profile['persistent'] = (bool) ($profile['persistent'] ?? true);
        $profile['tcp_keepalive'] = max(0, (int) ($profile['tcp_keepalive'] ?? 0));

        return self::$profiles[$name] = $profile;
    }

    /** @param array<string, mixed> $profile */
    private static function create(string $name, array $profile): Redis
    {
        $redis = new Redis();

        $host = (string) $profile['host'];
        $port = (int) $profile['port'];
        $timeout = (float) $profile['timeout'];
        $readTimeout = (float) $profile['read_timeout'];
        $persistent = (bool) $profile['persistent'];

        try {
            $connected = $persistent
                ? $redis->pconnect(
                    $host,
                    $port,
                    $timeout,
                    self::persistentId($name, $profile)
                )
                : $redis->connect($host, $port, $timeout);

            if (!$connected) {
                throw new RuntimeException(
                    "Unable to connect Redis profile [{$name}] at {$host}:{$port}."
                );
            }

            if (defined('Redis::OPT_READ_TIMEOUT')) {
                $redis->setOption(Redis::OPT_READ_TIMEOUT, $readTimeout);
            }

            if (
                ($profile['tcp_keepalive'] ?? 0) > 0
                && defined('Redis::OPT_TCP_KEEPALIVE')
            ) {
                $redis->setOption(
                    Redis::OPT_TCP_KEEPALIVE,
                    (int) $profile['tcp_keepalive']
                );
            }

            self::authenticate($redis, $profile, $name);

            $database = (int) $profile['database'];

            /*
             * DB 0 is the performance/default path and needs no SELECT command.
             * Non-zero logical DB profiles are supported for standalone Redis.
             * Their profile remains fixed after creation.
             */
            if ($database !== 0 && !$redis->select($database)) {
                throw new RuntimeException(
                    "Unable to select Redis database {$database} for profile [{$name}]."
                );
            }
        } catch (RuntimeException $exception) {
            throw $exception;
        } catch (RedisException $exception) {
            throw new RuntimeException(
                "Redis connection profile [{$name}] failed: {$exception->getMessage()}",
                0,
                $exception
            );
        }

        return $redis;
    }

    /** @param array<string, mixed> $profile */
    private static function authenticate(
        Redis $redis,
        array $profile,
        string $name
    ): void {
        $username = $profile['username'] ?? null;
        $password = $profile['password'] ?? null;

        if ($password === null || $password === '') {
            return;
        }

        $credentials = $username !== null && $username !== ''
            ? [(string) $username, (string) $password]
            : (string) $password;

        if (!$redis->auth($credentials)) {
            throw new RuntimeException(
                "Redis authentication failed for profile [{$name}]."
            );
        }
    }

    /** @param array<string, mixed> $profile */
    private static function persistentId(string $name, array $profile): string
    {
        $configured = trim((string) ($profile['persistent_id'] ?? ''));

        if ($configured !== '') {
            return $configured;
        }

        /*
         * ROOT_PATH keeps multiple applications sharing one FPM pool isolated.
         * The profile name keeps intentionally split named connections separate.
         */
        $identity = implode('|', [
            defined('ROOT_PATH') ? (string) ROOT_PATH : 'bhitti',
            $name,
            (string) $profile['host'],
            (string) $profile['port'],
            (string) $profile['database'],
            (string) ($profile['username'] ?? ''),
        ]);

        return 'bhitti:' . substr(hash('sha256', $identity), 0, 32);
    }

    private static function normalizeName(string $name): string
    {
        $name = trim($name);

        if ($name === '') {
            throw new RuntimeException(
                'Redis connection profile name cannot be empty.'
            );
        }

        return $name;
    }
}
