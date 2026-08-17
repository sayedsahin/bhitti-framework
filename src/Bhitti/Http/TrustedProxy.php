<?php

declare(strict_types=1);

namespace Bhitti\Http;

final class TrustedProxy
{
    public static function isSecureRequest(array $server): bool
    {
        if (!empty($server['HTTPS']) && $server['HTTPS'] !== 'off') {
            return true;
        }

        if (!self::isTrustedProxy($server)) {
            return false;
        }

        $proto = $server['HTTP_X_FORWARDED_PROTO'] ?? '';

        if ($proto !== '') {
            $firstProto = strtolower(trim(explode(',', $proto)[0]));

            if ($firstProto === 'https') {
                return true;
            }
        }

        $ssl = strtolower($server['HTTP_X_FORWARDED_SSL'] ?? '');

        return $ssl === 'on';
    }

    public static function isTrustedProxy(array $server): bool
    {
        $trusted = config('app.trusted_proxies', []);

        if ($trusted === []) {
            return false;
        }

        $proxyIp = $server['REMOTE_ADDR'] ?? '';

        if ($proxyIp === '') {
            return false;
        }

        foreach ($trusted as $trusted) {
            if (self::matches($proxyIp, (string) $trusted)) {
                return true;
            }
        }

        return false;
    }

    public static function clientIp(array $server): ?string
    {
        $remoteAddress = $server['REMOTE_ADDR'] ?? null;

        if (!$remoteAddress) {
            return null;
        }

        if (!self::isTrustedProxy($server)) {
            return $remoteAddress;
        }

        $forwardedFor = $server['HTTP_X_FORWARDED_FOR'] ?? '';

        if ($forwardedFor === '') {
            return $remoteAddress;
        }

        $trusted = (array) config('app.trusted_proxies', []);

        foreach (array_reverse(array_map('trim', explode(',', $forwardedFor))) as $ip) {
            if (!filter_var($ip, FILTER_VALIDATE_IP)) {
                continue;
            }

            $isTrusted = false;

            foreach ($trusted as $proxy) {
                if (self::matches($ip, (string) $proxy)) {
                    $isTrusted = true;
                    break;
                }
            }

            if (!$isTrusted) {
                return $ip;
            }
        }

        return $remoteAddress;
    }

    private static function matches(string $ip, string $trusted): bool
    {
        if (!str_contains($trusted, '/')) {
            return $ip === $trusted;
        }

        [$network, $prefix] = explode('/', $trusted, 2);

        $ipBinary = @inet_pton($ip);
        $networkBinary = @inet_pton($network);

        if (
            $ipBinary === false
            || $networkBinary === false
            || strlen($ipBinary) !== strlen($networkBinary)
            || !ctype_digit($prefix)
        ) {
            return false;
        }

        $prefix = (int) $prefix;
        $maxBits = strlen($ipBinary) * 8;

        if ($prefix < 0 || $prefix > $maxBits) {
            return false;
        }

        $bytes = intdiv($prefix, 8);
        $bits = $prefix % 8;

        if ($bytes > 0 && substr($ipBinary, 0, $bytes) !== substr($networkBinary, 0, $bytes)) {
            return false;
        }

        if ($bits === 0) {
            return true;
        }

        $mask = (0xFF << (8 - $bits)) & 0xFF;

        return (ord($ipBinary[$bytes]) & $mask)
            === (ord($networkBinary[$bytes]) & $mask);
    }
}
