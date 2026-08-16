<?php

declare(strict_types=1);

namespace Bhitti\Session\Drivers;

use Bhitti\Connector\MemcachedConnectionManager;
use Bhitti\Http\TrustedProxy;
use Bhitti\Session\SessionAccess;
use Bhitti\Session\SessionInterface;
use Bhitti\Session\SessionSaveHandler;
use Memcached;
use RuntimeException;
use Throwable;

final class MemcachedSession implements SessionInterface
{
    private ?Memcached $memcached = null;

    private bool $loaded = false;

    private bool $handlerRegistered = false;
    private ?SessionAccess $openingAccess = null;
    private ?string $lockedSessionId = null;
    private ?string $lockToken = null;

    public function __construct(
        private readonly array $sessionConfig
    ) {
    }

    public function start(SessionAccess $access): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            $this->loaded = true;
            return;
        }

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

        $this->openingAccess = $access;

        try {
            if (!session_start($options)) {
                throw new RuntimeException('Unable to start Memcached session.');
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
            throw new RuntimeException('Unable to regenerate Memcached session ID.');
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
            $this->releaseLock();
        }

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
            read: fn(string $id): string => $this->read($id),
            write: fn(string $id, string $data): bool => $this->write($id, $data),
            destroy: fn(string $id): bool => $this->delete($id),
            validate: fn(string $id): bool => $this->validateSessionId($id),
            update: fn(string $id, string $data): bool => $this->updateTimestamp($id, $data)
        );

        if (!session_set_save_handler($handler, true)) {
            throw new RuntimeException('Unable to register Memcached session handler.');
        }

        $this->handlerRegistered = true;
    }

    private function read(string $id): string
    {
        /*
         * Pure READ requests do not need an exclusive lock because a Memcached
         * session value is fetched as one complete value. WRITE access still
         * acquires the lock before reading the latest state.
         */
        if ($this->openingAccess === SessionAccess::WRITE) {
            $this->acquireLock($id);
        }

        $data = $this->client()->get($this->key($id));

        return is_string($data) ? $data : '';
    }

    private function write(string $id, string $data): bool
    {
        $this->ensureWriteLock($id);

        return $this->client()->set(
            $this->key($id),
            $data,
            $this->lifetime()
        );
    }

    private function delete(string $id): bool
    {
        $this->ensureWriteLock($id);

        try {
            $client = $this->client();
            $deleted = $client->delete($this->key($id));

            if ($deleted || $client->getResultCode() === Memcached::RES_NOTFOUND) {
                return true;
            }

            return false;
        } finally {
            if ($this->lockedSessionId === $id) {
                $this->releaseLock();
            }
        }
    }

    private function validateSessionId(string $id): bool
    {
        $client = $this->client();
        $client->get($this->key($id));
        $result = $client->getResultCode();

        if ($result === Memcached::RES_SUCCESS) {
            return true;
        }

        if ($result === Memcached::RES_NOTFOUND) {
            return false;
        }

        throw new RuntimeException(
            'Memcached session validation failed: ' . $client->getResultMessage()
        );
    }

    private function updateTimestamp(string $id, string $data): bool
    {
        if ($this->lockedSessionId !== null) {
            $this->refreshOwnedLock($id);
        }

        $client = $this->client();
        $key = $this->key($id);

        if ($client->touch($key, $this->lifetime())) {
            return true;
        }

        if ($client->getResultCode() === Memcached::RES_NOTFOUND) {
            return $client->set($key, $data, $this->lifetime());
        }

        return false;
    }

    private function client(): Memcached
    {
        return $this->memcached ??= MemcachedConnectionManager::connection();
    }

    private function key(string $id): string
    {
        return rtrim((string) ($this->sessionConfig['prefix'] ?? 'bhitti:session:'), ':')
            . ':' . $id;
    }

    private function lockKey(string $id): string
    {
        return $this->key($id) . ':lock';
    }

    private function acquireLock(string $id): void
    {
        if (!($this->sessionConfig['lock'] ?? true)) {
            return;
        }

        if ($this->lockedSessionId === $id) {
            return;
        }

        $token = bin2hex(random_bytes(16));
        $ttl = $this->lockTtlSeconds();
        $wait = max(0.0, (float) ($this->sessionConfig['lock_wait'] ?? 2.0));
        $sleep = max(1000, (int) ($this->sessionConfig['lock_sleep'] ?? 20000));
        $deadline = microtime(true) + $wait;

        do {
            if ($this->client()->add($this->lockKey($id), $token, $ttl)) {
                $this->lockedSessionId = $id;
                $this->lockToken = $token;
                return;
            }

            if ($wait <= 0.0) {
                break;
            }

            usleep($sleep);
        } while (microtime(true) < $deadline);

        throw new RuntimeException('Unable to acquire Memcached session lock.');
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
            throw new RuntimeException('Memcached session lock ownership lost.');
        }

        $client = $this->client();
        $current = $client->get(
            $this->lockKey($id),
            null,
            Memcached::GET_EXTENDED
        );

        if (
            $client->getResultCode() !== Memcached::RES_SUCCESS
            || !is_array($current)
            || ($current['value'] ?? null) !== $this->lockToken
            || !isset($current['cas'])
        ) {
            throw new RuntimeException('Memcached session lock ownership lost.');
        }

        if (!$client->cas(
            $current['cas'],
            $this->lockKey($id),
            $this->lockToken,
            $this->lockTtlSeconds()
        )) {
            throw new RuntimeException('Unable to refresh Memcached session lock.');
        }
    }

    private function lockTtlSeconds(): int
    {
        return max(
            1,
            min((int) ($this->sessionConfig['lock_ttl'] ?? 30), 2_592_000)
        );
    }

    private function releaseLock(): void
    {
        if ($this->lockedSessionId === null || $this->lockToken === null) {
            return;
        }

        $sessionId = $this->lockedSessionId;
        $token = $this->lockToken;

        $this->lockedSessionId = null;
        $this->lockToken = null;

        if ($this->memcached === null) {
            return;
        }

        try {
            $lockKey = $this->lockKey($sessionId);

            if ($this->client()->get($lockKey) === $token) {
                $this->client()->delete($lockKey);
            }
        } catch (Throwable) {
            // Lock expires automatically through its TTL.
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

    private function lifetime(): int
    {
        return max(1, (int) ($this->sessionConfig['lifetime'] ?? 7200));
    }
}
