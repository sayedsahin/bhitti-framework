<?php

declare(strict_types=1);

namespace Bhitti\Session;

final class Session
{
    private static SessionInterface $driver;

    public static function setDriver(SessionInterface $driver): void
    {
        self::$driver = $driver;
    }

    private static function driver(): SessionInterface
    {
        if (!isset(self::$driver)) {
            throw new \RuntimeException('Session driver not initialized');
        }

        return self::$driver;
    }

    public static function replace(SessionInterface $driver): void
    {
        self::$driver = $driver;
    }


    public static function get(string $key, mixed $default = null): mixed
    {
        $driver = self::driver();
        $driver->start(SessionAccess::READ);

        return $driver->get($key, $default);
    }

    public static function set(string $key, mixed $value): void
    {
        $driver = self::driver();
        $driver->start(SessionAccess::WRITE);
        $driver->set($key, $value);
    }

    public static function forget(string $key): void
    {
        $driver = self::driver();
        $driver->start(SessionAccess::WRITE);
        $driver->forget($key);
    }

    public static function flush(): void
    {
        $driver = self::driver();
        $driver->start(SessionAccess::WRITE);
        $driver->flush();
    }

    public static function regenerate(): void
    {
        $driver = self::driver();
        $driver->start(SessionAccess::WRITE);
        $driver->regenerate();
    }

    public static function destroy(): void
    {
        $driver = self::driver();
        $driver->start(SessionAccess::WRITE);
        $driver->destroy();
    }

    public static function close(): void
    {
        self::driver()->close();
    }
}
