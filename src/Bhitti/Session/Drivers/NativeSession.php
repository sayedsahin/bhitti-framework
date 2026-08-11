<?php

declare(strict_types=1);

namespace Bhitti\Session\Drivers;

use Bhitti\Http\TrustedProxy;
use Bhitti\Session\SessionAccess;
use Bhitti\Session\SessionInterface;
use RuntimeException;

final class NativeSession implements SessionInterface
{
    private bool $loaded = false;

    public function __construct(
        private readonly array $config
    ) {
    }

    public function start(SessionAccess $access): void
    {
        /*
         * If PHP already has an active session, it is writable. There is no
         * reason to reopen it for either READ or WRITE.
         */
        if (session_status() === PHP_SESSION_ACTIVE) {
            $this->loaded = true;
            return;
        }

        /*
         * READ data was already loaded into $_SESSION earlier in this request.
         * Keep using the in-memory copy without reopening the backend.
         */
        if ($access === SessionAccess::READ && $this->loaded) {
            return;
        }

        $name = (string) ($this->config['name'] ?? 'BHITTISESSID');

        if ($name !== '') {
            session_name($name);
        }

        $options = $this->options();

        if ($access === SessionAccess::READ) {
            /*
             * CHANGED:
             * READ access loads session data and asks PHP to release the
             * native session lock immediately.
             */
            $options['read_and_close'] = true;
        }

        if (!session_start($options)) {
            throw new RuntimeException('Unable to start native session.');
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
            throw new RuntimeException('Unable to regenerate native session ID.');
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

        $this->loaded = false;
    }

    public function close(): void
    {

        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        /*
         * Keep $loaded=true: $_SESSION remains available for repeated reads
         * in this request. A later WRITE will reopen and re-read the latest
         * backend state before mutation.
         */
    }

    private function options(): array
    {
        return [
            'use_strict_mode' => 1,
            'use_only_cookies' => 1,
            'cookie_lifetime' => 0,
            'cookie_path' => (string) ($this->config['path'] ?? '/'),
            'cookie_domain' => (string) ($this->config['domain'] ?? ''),
            'cookie_httponly' => (bool) ($this->config['httponly'] ?? true),
            'cookie_secure' => (bool) ($this->config['secure'] ?? false)
                && TrustedProxy::isSecureRequest($_SERVER),
            'cookie_samesite' => (string) ($this->config['samesite'] ?? 'Lax'),
            'gc_maxlifetime' => max(1, (int) ($this->config['lifetime'] ?? 7200)),
        ];
    }
}
