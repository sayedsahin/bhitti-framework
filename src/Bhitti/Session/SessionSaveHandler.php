<?php

declare(strict_types=1);

namespace Bhitti\Session;

use Closure;
use SessionHandlerInterface;
use SessionUpdateTimestampHandlerInterface;

final readonly class SessionSaveHandler implements SessionHandlerInterface, SessionUpdateTimestampHandlerInterface
{
    public function __construct(
        private Closure $close,
        private Closure $read,
        private Closure $write,
        private Closure $destroy,
        private Closure $validate,
        private Closure $update
    ) {
    }

    public function open(string $path, string $name): bool
    {
        return true;
    }

    public function close(): bool
    {
        return ($this->close)();
    }

    public function read(string $id): string|false
    {
        return ($this->read)($id);
    }

    public function write(string $id, string $data): bool
    {
        return ($this->write)($id, $data);
    }

    public function destroy(string $id): bool
    {
        return ($this->destroy)($id);
    }

    public function gc(int $maxLifetime): int|false
    {
        return 0;
    }

    public function validateId(string $id): bool
    {
        return ($this->validate)($id);
    }

    public function updateTimestamp(string $id, string $data): bool
    {
        return ($this->update)($id, $data);
    }
}
