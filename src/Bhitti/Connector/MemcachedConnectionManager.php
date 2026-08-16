<?php

declare(strict_types=1);

namespace Bhitti\Connector;

use Memcached;
use RuntimeException;

final class MemcachedConnectionManager
{
    private static ?Memcached $connection = null;

    public static function connection(): Memcached
    {
        if (self::$connection !== null) {
            return self::$connection;
        }

        if (!class_exists(Memcached::class)) {
            throw new RuntimeException('PHP Memcached extension is not installed.');
        }

        $config = (array) config('database.memcached', []);
        $servers = self::servers((array) ($config['servers'] ?? []));
        $persistentId = trim((string) ($config['persistent_id'] ?? ''));

        $client = $persistentId !== ''
            ? new Memcached($persistentId)
            : new Memcached();

        if ($client->getServerList() === []) {
            $client->setOption(Memcached::OPT_BINARY_PROTOCOL, true);
            $client->setOption(
                Memcached::OPT_CONNECT_TIMEOUT,
                (int) ($config['connect_timeout'] ?? 2000)
            );

            if (count($servers) > 1) {
                $client->setOption(Memcached::OPT_LIBKETAMA_COMPATIBLE, true);
            }

            if (!$client->addServers($servers)) {
                throw new RuntimeException('Unable to configure Memcached servers.');
            }
        }

        return self::$connection = $client;
    }

    public static function reset(): void
    {
        self::$connection = null;
    }

    private static function servers(array $servers): array
    {
        if ($servers === []) {
            throw new RuntimeException('No Memcached server is configured.');
        }

        $normalized = [];

        foreach ($servers as $server) {
            $host = trim((string) ($server['host'] ?? ''));
            $port = (int) ($server['port'] ?? 11211);
            $weight = (int) ($server['weight'] ?? 0);

            if ($host === '' || $port < 1 || $port > 65535 || $weight < 0) {
                throw new RuntimeException('Invalid Memcached server configuration.');
            }

            $normalized[] = [$host, $port, $weight];
        }

        return $normalized;
    }
}
