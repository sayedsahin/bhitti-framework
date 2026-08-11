<?php

declare(strict_types=1);

namespace Bhitti\RateLimit\RateLimitDriver;

use Bhitti\RateLimit\RateLimitResult;
use Bhitti\Connector\RedisConnectionManager;
use Redis;
use RedisException;
use RuntimeException;

final class RedisDriver implements RateLimitDriverInterface
{
    private const HIT_SCRIPT = <<<'LUA'
local current = redis.call('INCR', KEYS[1])

if current == 1 then
    redis.call('EXPIRE', KEYS[1], ARGV[1])
end

local ttl = redis.call('TTL', KEYS[1])

if ttl < 1 then
    redis.call('EXPIRE', KEYS[1], ARGV[1])
    ttl = tonumber(ARGV[1])
end

return {current, ttl}
LUA;

    private ?Redis $redis = null;

    public function __construct(
        private readonly string $connection = 'default'
    ) {
    }

    private function redis(): Redis
    {
        return $this->redis ??= RedisConnectionManager::connection(
            $this->connection
        );
    }

    public function hit(
        string $key,
        int $maxAttempts,
        int $windowSeconds
    ): RateLimitResult {
        try {
            $result = $this->redis()->eval(
                self::HIT_SCRIPT,
                [$key, (string) $windowSeconds],
                1
            );
        } catch (RedisException $exception) {
            throw new RuntimeException(
                'Redis rate-limit operation failed: '
                . $exception->getMessage(),
                0,
                $exception
            );
        }

        if (
            !is_array($result)
            || count($result) !== 2
            || !is_numeric($result[0])
            || !is_numeric($result[1])
        ) {
            throw new RuntimeException(
                'Redis returned an invalid rate-limit result.'
            );
        }

        $attempts = (int) $result[0];
        $ttl = max(1, (int) $result[1]);
        $now = time();

        return RateLimitResult::fromCounter(
            $attempts,
            $maxAttempts,
            $now + $ttl,
            $now
        );
    }

    public function clear(string $key): void
    {
        try {
            $this->redis()->del($key);
        } catch (RedisException $exception) {
            throw new RuntimeException(
                'Unable to clear Redis rate-limit counter.',
                0,
                $exception
            );
        }
    }
}
