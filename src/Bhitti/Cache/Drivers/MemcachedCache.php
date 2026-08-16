<?php

declare(strict_types=1);

namespace Bhitti\Cache\Drivers;

use Bhitti\Cache\CacheInterface;
use Bhitti\Connector\MemcachedConnectionManager;
use InvalidArgumentException;

final class MemcachedCache implements CacheInterface
{
    private \Memcached $memcached;
    private string $prefix;
    private string $versionKey;
    private ?string $namespace = null;

    public function __construct(string $prefix = 'bhitti:cache:')
    {
        $prefix = trim($prefix);

        if ($prefix === '') {
            throw new InvalidArgumentException('Memcached cache prefix cannot be empty.');
        }

        if (strlen($prefix) > 100) {
            throw new InvalidArgumentException('Memcached cache prefix is too long.');
        }

        $this->prefix = rtrim($prefix, ':') . ':';
        $this->versionKey = $this->prefix . '__version';

        $this->memcached = MemcachedConnectionManager::connection();
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $value = $this->memcached->get($this->key($key));

        return $this->memcached->getResultCode() === \Memcached::RES_SUCCESS
            ? $value
            : $default;
    }

    public function put(string $key, mixed $value, int $ttl = 0): void
    {
        $this->memcached->set($this->key($key), $value, $this->expiration($ttl));

    }

    public function has(string $key): bool
    {
        $this->memcached->get($this->key($key));

        return $this->memcached->getResultCode() === \Memcached::RES_SUCCESS;
    }

    public function forget(string $key): void
    {
        $this->memcached->delete($this->key($key));
    }

    public function flush(): void
    {
        $version = bin2hex(random_bytes(8));

        if ($this->memcached->set($this->versionKey, $version, 0)) {
            $this->namespace = $this->prefix . $version . ':';
        }
    }

    private function key(string $key): string
    {
        return $this->namespace() . hash('sha256', $key);
    }

    private function namespace(): string
    {
        if ($this->namespace !== null) {
            return $this->namespace;
        }

        $version = $this->memcached->get($this->versionKey);

        if (
            $this->memcached->getResultCode() !== \Memcached::RES_SUCCESS
            || !is_string($version)
            || $version === ''
        ) {
            $version = '1';

            if (!$this->memcached->add($this->versionKey, $version, 0)) {
                $storedVersion = $this->memcached->get($this->versionKey);

                if (
                    $this->memcached->getResultCode() === \Memcached::RES_SUCCESS
                    && is_string($storedVersion)
                    && $storedVersion !== ''
                ) {
                    $version = $storedVersion;
                }
            }
        }

        return $this->namespace = $this->prefix . $version . ':';
    }

    private function expiration(int $ttl): int
    {
        if ($ttl === 0) {
            return 0;
        }

        return $ttl > 2592000 ? time() + $ttl : $ttl;
    }
}