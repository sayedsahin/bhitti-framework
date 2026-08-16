<?php

declare(strict_types=1);

namespace Bhitti\RateLimit\RateLimitDriver;

use Bhitti\Connector\MemcachedConnectionManager;
use Bhitti\RateLimit\RateLimitResult;
use Memcached;
use RuntimeException;

final class MemcachedDriver implements RateLimitDriverInterface
{
    private Memcached $memcached;

    public function __construct()
    {
        $this->memcached = MemcachedConnectionManager::connection();
    }

    public function hit(
        string $key,
        int $maxAttempts,
        int $windowSeconds
    ): RateLimitResult {
        $counterKey = $key . ':count';
        $resetKey = $key . ':reset';
        $now = time();

        $attempts = $this->memcached->increment(
            $counterKey,
            1,
            1,
            $windowSeconds
        );

        if ($attempts === false) {
            throw new RuntimeException(
                'Memcached rate-limit operation failed: '
                . $this->memcached->getResultMessage()
            );
        }

        $attempts = (int) $attempts;

        if ($attempts === 1) {
            $resetAt = $now + $windowSeconds;

            if (
                !$this->memcached->set(
                    $resetKey,
                    $resetAt,
                    $windowSeconds
                )
            ) {
                throw new RuntimeException(
                    'Unable to store Memcached reset time: '
                    . $this->memcached->getResultMessage()
                );
            }
        } else {
            $resetAt = $this->memcached->get($resetKey);

            if ($resetAt === false) {
                $resultCode = $this->memcached->getResultCode();

                if ($resultCode !== Memcached::RES_NOTFOUND) {
                    throw new RuntimeException(
                        'Unable to read Memcached reset time: '
                        . $this->memcached->getResultMessage()
                    );
                }

                $resetAt = $now + $windowSeconds;
                $this->memcached->add(
                    $resetKey,
                    $resetAt,
                    $windowSeconds
                );

                $storedReset = $this->memcached->get($resetKey);

                if ($storedReset !== false) {
                    $resetAt = $storedReset;
                }
            }
        }

        return RateLimitResult::fromCounter(
            $attempts,
            $maxAttempts,
            (int) $resetAt,
            $now
        );
    }

    public function clear(string $key): void
    {
        foreach ([$key . ':count', $key . ':reset'] as $item) {
            $deleted = $this->memcached->delete($item);

            if (
                !$deleted
                && $this->memcached->getResultCode()
                    !== Memcached::RES_NOTFOUND
            ) {
                throw new RuntimeException(
                    'Unable to clear Memcached rate-limit counter: '
                    . $this->memcached->getResultMessage()
                );
            }
        }
    }

}
