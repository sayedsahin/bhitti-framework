<?php

declare(strict_types=1);

namespace Bhitti\Cache\Drivers;

use Bhitti\Cache\CacheInterface;
use RuntimeException;

final class FileCache implements CacheInterface
{
    private string $path;

    public function __construct(string $path)
    {
        $this->path = rtrim($path, '/');

        if (!is_dir($path) && !mkdir($path, 0775, true) && !is_dir($path)) {
            throw new RuntimeException(
                "Unable to create cache directory: {$path}"
            );
        }

        if (!is_writable($path)) {
            throw new RuntimeException(
                "Cache directory is not writable: {$path}"
            );
        }

        $this->path = $path;
    }

    private function file(string $key): string
    {
        return $this->path . '/' . md5($key) . '.cache';
    }

    private function lock()
    {
        $handle = @fopen($this->path . '/.lock', 'c+b');

        if ($handle === false) {
            throw new RuntimeException('Unable to open cache lock file.');
        }

        return $handle;
    }

    private function read(string $file): ?array
    {
        $payload = @file_get_contents($file);

        if (!is_string($payload) || $payload === '') {
            return null;
        }

        $data = @unserialize($payload);

        if (!is_array($data) || !array_key_exists('value', $data) || !array_key_exists('expires', $data)) {
            return null;
        }

        return $data;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $this->gc();

        $file = $this->file($key);
        $lock = $this->lock();

        if (!flock($lock, LOCK_SH)) {
            fclose($lock);
            return $default;
        }

        $data = $this->read($file);
        flock($lock, LOCK_UN);

        if ($data === null) {
            fclose($lock);
            return $default;
        }

        $expires = (int) $data['expires'];

        if ($expires === 0 || $expires > time()) {
            fclose($lock);
            return $data['value'];
        }

        if (!flock($lock, LOCK_EX)) {
            fclose($lock);
            return $default;
        }

        try {
            /* Re-check after getting the exclusive lock. */
            $data = $this->read($file);

            if ($data === null) {
                return $default;
            }

            $expires = (int) $data['expires'];

            if ($expires !== 0 && $expires <= time()) {
                @unlink($file);
                return $default;
            }

            return $data['value'];
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public function put(string $key, mixed $value, int $ttl = 0): void
    {
        $this->gc();

        $file = $this->file($key);
        $lock = $this->lock();
        $payload = serialize([
            'value' => $value,
            'expires' => $ttl > 0 ? time() + $ttl : 0,
        ]);

        try {
            if (!flock($lock, LOCK_EX)) {
                throw new RuntimeException('Unable to lock cache.');
            }

            if (file_put_contents($file, $payload, LOCK_EX) === false) {
                throw new RuntimeException(
                    "Unable to write cache file: {$file}"
                );
            }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public function has(string $key): bool
    {
        $missing = new \stdClass();

        return $this->get($key, $missing) !== $missing;
    }

    public function forget(string $key): void
    {
        $lock = $this->lock();

        try {
            if (flock($lock, LOCK_EX)) {
                @unlink($this->file($key));
            }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public function flush(): void
    {
        $lock = $this->lock();

        try {
            if (!flock($lock, LOCK_EX)) {
                return;
            }

            foreach (glob($this->path . '/*.cache') ?: [] as $file) {
                @unlink($file);
            }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function gc(): void
    {
        if (mt_rand(1, 1000) !== 1) {
            return;
        }

        $lock = $this->lock();

        try {
            if (!flock($lock, LOCK_EX | LOCK_NB)) {
                return;
            }

            $bucket = sprintf('%02x', mt_rand(0, 255));
            $now = time();

            foreach (glob($this->path . '/' . $bucket . '*.cache') ?: [] as $file) {
                $data = $this->read($file);
                $expires = (int) ($data['expires'] ?? 0);

                if ($expires !== 0 && $expires <= $now) {
                    @unlink($file);
                }
            }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}
