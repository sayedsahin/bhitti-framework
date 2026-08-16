<?php

declare(strict_types=1);

namespace Bhitti\Session\Drivers;

use Bhitti\Http\TrustedProxy;
use Bhitti\Session\SessionAccess;
use Bhitti\Session\SessionInterface;
use Bhitti\Session\SessionSaveHandler;
use Bhitti\Connector\RedisConnectionManager;
use Redis;
use RedisException;
use RuntimeException;
use Throwable;

final class RedisSession implements SessionInterface
{
    private ?Redis $redis = null;


    private bool $loaded = false;

    private bool $handlerRegistered = false;
    private ?SessionAccess $openingAccess = null;
    private ?string $lockedSessionId = null;
    private ?string $lockToken = null;

    public function __construct(
        private readonly array $sessionConfig,
        private readonly string $connection = 'default'
    ) {
    }

    public function start(SessionAccess $access): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            $this->loaded = true;
            return;
        }

        /*
         * A previous READ already populated $_SESSION and released the lock.
         * Repeated reads stay in memory and do not hit Redis again.
         */
        if ($access === SessionAccess::READ && $this->loaded) {
            return;
        }

        $this->registerHandler();

        $name = (string) ($this->sessionConfig['name'] ?? 'BHITTISESSID');

        if ($name !== '') {
            session_name($name);
        }

        $options = $this->options();

        if ($access === SessionAccess::READ) {
            $options['read_and_close'] = true;
        }

        /*
         * readSession() uses this flag to decide whether an exclusive lock is
         * required. Pure READ access does not take the Redis session lock.
         */
        $this->openingAccess = $access;

        try {
            if (!session_start($options)) {
                throw new RuntimeException('Unable to start Redis session.');
            }
        } finally {
            $this->openingAccess = null;
        }

        $this->loaded = true;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $_SESSION[$key] ?? $default;
    }

    public function set(string $key, mixed $value): void
    {
        $_SESSION[$key] = $value;
    }

    public function forget(string $key): void
    {
        unset($_SESSION[$key]);
    }

    public function flush(): void
    {
        $_SESSION = [];
    }

    public function regenerate(): void
    {
        if (!session_regenerate_id(true)) {
            throw new RuntimeException('Unable to regenerate Redis session ID.');
        }
    }

    public function destroy(): void
    {
        $_SESSION = [];

        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();

            setcookie(session_name(), '', [
                'expires' => time() - 3600,
                'path' => $params['path'],
                'domain' => $params['domain'],
                'secure' => $params['secure'],
                'httponly' => $params['httponly'],
                'samesite' => $params['samesite'] ?? 'Lax',
            ]);
        }

        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }

        $this->releaseLock();
        $this->loaded = false;
    }

    public function close(): void
    {

        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        } else {
            /* Safety for a custom-handler failure path. */
            $this->releaseLock();
        }

        /* Keep loaded data available for repeated reads in this request. */
    }

    private function registerHandler(): void
    {
        if ($this->handlerRegistered) {
            return;
        }

        $handler = new SessionSaveHandler(
            close: function (): bool {
                $this->releaseLock();
                return true;
            },
            read: fn(string $id): string => $this->readSession($id),
            write: fn(string $id, string $data): bool => $this->writeSession($id, $data),
            destroy: fn(string $id): bool => $this->destroySession($id),
            validate: fn(string $id): bool => $this->validateSessionId($id),
            update: fn(string $id, string $data): bool => $this->updateTimestamp($id, $data)
        );

        if (!session_set_save_handler($handler, true)) {
            throw new RuntimeException('Unable to register Redis session handler.');
        }

        $this->handlerRegistered = true;
    }

    private function readSession(string $id): string
    {
        if ($this->openingAccess === SessionAccess::WRITE) {
            $this->acquireLock($id);
        }

        try {
            $value = $this->redis()->get($this->sessionKey($id));
        } catch (RedisException $exception) {
            throw $this->connectionException($exception);
        }

        return is_string($value) ? $value : '';
    }

    private function writeSession(string $id, string $data): bool
    {
        $this->ensureWriteLock($id);

        try {
            return $this->redis()->setex(
                $this->sessionKey($id),
                $this->lifetime(),
                $data
            );
        } catch (RedisException $exception) {
            throw $this->connectionException($exception);
        }
    }

    private function destroySession(string $id): bool
    {
        $this->ensureWriteLock($id);

        try {
            $this->redis()->del($this->sessionKey($id));
            return true;
        } catch (RedisException $exception) {
            throw $this->connectionException($exception);
        } finally {
            if ($this->lockedSessionId === $id) {
                $this->releaseLock();
            }
        }
    }

    private function validateSessionId(string $id): bool
    {
        try {
            return $this->redis()->exists($this->sessionKey($id)) > 0;
        } catch (RedisException $exception) {
            throw $this->connectionException($exception);
        }
    }

    private function updateTimestamp(string $id, string $data): bool
    {
        if ($this->lockedSessionId !== null) {
            $this->refreshOwnedLock($id);
        }

        try {
            $redis = $this->redis();
            $key = $this->sessionKey($id);

            if ($redis->expire($key, $this->lifetime())) {
                return true;
            }

            return $redis->setex($key, $this->lifetime(), $data);
        } catch (RedisException $exception) {
            throw $this->connectionException($exception);
        }
    }

    private function redis(): Redis
    {
        return $this->redis ??= RedisConnectionManager::connection(
            $this->connection
        );
    }

    private function acquireLock(string $id): void
    {
        if (!($this->sessionConfig['lock'] ?? true)) {
            return;
        }

        if ($this->lockedSessionId === $id) {
            return;
        }

        $redis = $this->redis();
        $lockKey = $this->lockKey($id);
        $token = bin2hex(random_bytes(16));
        $ttl = $this->lockTtlMilliseconds();
        $wait = max(0.0, (float) ($this->sessionConfig['lock_wait'] ?? 2.0));
        $sleep = max(1000, (int) ($this->sessionConfig['lock_sleep'] ?? 20000));
        $deadline = microtime(true) + $wait;

        do {
            try {
                $locked = $redis->set($lockKey, $token, [
                    'nx',
                    'px' => $ttl,
                ]);
            } catch (RedisException $exception) {
                throw $this->connectionException($exception);
            }

            if ($locked === true) {
                $this->lockedSessionId = $id;
                $this->lockToken = $token;
                return;
            }

            if ($wait <= 0.0) {
                break;
            }

            usleep($sleep);
        } while (microtime(true) < $deadline);

        throw new RuntimeException('Unable to acquire Redis session lock.');
    }


    private function ensureWriteLock(string $id): void
    {
        if (!($this->sessionConfig['lock'] ?? true)) {
            return;
        }

        if ($this->lockedSessionId === null) {
            $this->acquireLock($id);
            return;
        }

        if ($this->lockedSessionId !== $id) {
            $this->releaseLock();
            $this->acquireLock($id);
            return;
        }

        $this->refreshOwnedLock($id);
    }

    private function refreshOwnedLock(string $id): void
    {
        if (!($this->sessionConfig['lock'] ?? true)) {
            return;
        }

        if ($this->lockedSessionId !== $id || $this->lockToken === null) {
            throw new RuntimeException('Redis session lock ownership lost.');
        }

        try {
            $refreshed = $this->redis()->eval(
                <<<'LUA'
if redis.call('get', KEYS[1]) == ARGV[1] then
    return redis.call('pexpire', KEYS[1], ARGV[2])
end
return 0
LUA,
                [
                    $this->lockKey($id),
                    $this->lockToken,
                    (string) $this->lockTtlMilliseconds(),
                ],
                1
            );
        } catch (RedisException $exception) {
            throw $this->connectionException($exception);
        }

        if ((int) $refreshed !== 1) {
            throw new RuntimeException('Redis session lock ownership lost.');
        }
    }

    private function lockTtlMilliseconds(): int
    {
        return max(
            1000,
            (int) ($this->sessionConfig['lock_ttl'] ?? 30) * 1000
        );
    }

    private function releaseLock(): void
    {
        if ($this->lockedSessionId === null || $this->lockToken === null) {
            return;
        }

        $lockKey = $this->lockKey($this->lockedSessionId);
        $token = $this->lockToken;

        $this->lockedSessionId = null;
        $this->lockToken = null;

        if ($this->redis === null) {
            return;
        }

        try {
            $this->redis->eval(
                <<<'LUA'
if redis.call('get', KEYS[1]) == ARGV[1] then
    return redis.call('del', KEYS[1])
end
return 0
LUA,
                [$lockKey, $token],
                1
            );
        } catch (Throwable) {
            // The lock expires automatically through its TTL.
        }
    }

    private function options(): array
    {
        return [
            'use_strict_mode' => 1,
            'use_only_cookies' => 1,
            'cookie_lifetime' => 0,
            'cookie_path' => (string) ($this->sessionConfig['path'] ?? '/'),
            'cookie_domain' => (string) ($this->sessionConfig['domain'] ?? ''),
            'cookie_httponly' => (bool) ($this->sessionConfig['httponly'] ?? true),
            'cookie_secure' => (bool) ($this->sessionConfig['secure'] ?? false)
                && TrustedProxy::isSecureRequest($_SERVER),
            'cookie_samesite' => (string) ($this->sessionConfig['samesite'] ?? 'Lax'),
            'gc_maxlifetime' => $this->lifetime(),
        ];
    }

    private function sessionKey(string $id): string
    {
        return $this->prefix() . $id;
    }

    private function lockKey(string $id): string
    {
        return $this->prefix() . 'lock:' . $id;
    }

    private function prefix(): string
    {
        return (string) ($this->sessionConfig['prefix'] ?? 'bhitti:session:');
    }

    private function lifetime(): int
    {
        return max(1, (int) ($this->sessionConfig['lifetime'] ?? 7200));
    }

    private function connectionException(RedisException $exception): RuntimeException
    {
        return new RuntimeException(
            'Redis session operation failed: ' . $exception->getMessage(),
            0,
            $exception
        );
    }
}
