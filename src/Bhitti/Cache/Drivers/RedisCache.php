<?php

declare(strict_types=1);

namespace Bhitti\Cache\Drivers;

use Bhitti\Cache\CacheInterface;
use Bhitti\Connector\RedisConnectionManager;
use Redis;

final class RedisCache implements CacheInterface
{
    private ?Redis $redis = null;

    public function __construct(
        private readonly string $connection = 'default',
        private readonly string $prefix = 'bhitti:cache:'
    ) {
    }

    private function redis(): Redis
    {
        return $this->redis ??= RedisConnectionManager::connection(
            $this->connection
        );
    }

    private function key(string $key): string
    {
        return $this->prefix . $key;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $value = $this->redis()->get($this->key($key));

        if ($value === false) {
            return $default;
        }

        try {
            return unserialize($value);
        } catch (\Throwable) {
            return $default;
        }
    }

    public function put(string $key, mixed $value, int $ttl = 0): void
    {
        $payload = serialize($value);
        $key = $this->key($key);

        if ($ttl > 0) {
            $this->redis()->setex($key, $ttl, $payload);
        } else {
            $this->redis()->set($key, $payload);
        }
    }

    public function has(string $key): bool
    {
        return $this->redis()->exists($this->key($key)) > 0;
    }

    public function forget(string $key): void
    {
        $this->redis()->del($this->key($key));
    }

    public function flush(): void
    {
        /* Prefix-safe flush: never FLUSHDB a shared Redis connection. */
        $redis = $this->redis();
        $iterator = null;

        do {
            $keys = $redis->scan($iterator, $this->prefix . '*', 100);

            if (is_array($keys) && $keys !== []) {
                if (method_exists($redis, 'unlink')) {
                    $redis->unlink($keys);
                } else {
                    $redis->del($keys);
                }
            }
        } while ($iterator !== 0 && $iterator !== '0');
    }
}