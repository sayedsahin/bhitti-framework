<?php

declare(strict_types=1);

namespace Bhitti\Session;

use Bhitti\Session\Drivers\MemcachedSession;
use Bhitti\Session\Drivers\NativeSession;
use Bhitti\Session\Drivers\NullSession;
use Bhitti\Session\Drivers\RedisSession;
use RuntimeException;

final class SessionManager
{
    public static function configure(array $config): void
    {
        $driverName = $config['driver'] ?? 'native';

        /*
         * IMPORTANT:
         * configure() only creates/configures the driver object.
         * It does NOT start a PHP session and Redis/Memcached constructors do
         * NOT connect to their backend here. Actual session I/O stays lazy and
         * begins only on the first Session::get()/set()/etc. operation.
         */
        $driver = match ($driverName) {
            'native' => new NativeSession($config),
            'redis' => new RedisSession($config, config('database.redis')),
            'memcached' => new MemcachedSession($config, config('database.memcached')),
            'null' => new NullSession($config),

            default => throw new RuntimeException(
                'Unsupported session driver: ' . $driverName
            ),
        };

        Session::setDriver($driver);
    }
}
