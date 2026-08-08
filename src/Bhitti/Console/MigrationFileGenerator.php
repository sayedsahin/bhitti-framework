<?php

declare(strict_types=1);

namespace Bhitti\Console;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use RuntimeException;

final class MigrationFileGenerator
{
    public function create(string $table, ?string $path = null): array
    {
        $this->assertTableName($table);

        return $this->generate(
            "create_{$table}_table",
            $this->createContent($table),
            $path
        );
    }

    public function alter(string $table, ?string $path = null): array
    {
        $this->assertTableName($table);

        return $this->generate(
            "alter_{$table}_table",
            $this->alterContent($table),
            $path
        );
    }

    private function generate(string $name, string $content, ?string $path): array
    {
        $directory = $this->migrationPath($path);
        $this->ensureDirectory($directory);

        $lock = fopen(
            $directory . DIRECTORY_SEPARATOR . '.migration-create.lock',
            'c+'
        );

        if ($lock === false) {
            throw new RuntimeException(
                "Unable to open migration creation lock in [{$directory}]."
            );
        }

        try {
            if (!flock($lock, LOCK_EX)) {
                throw new RuntimeException(
                    'Unable to acquire the migration creation lock.'
                );
            }

            [$filename, $filePath] = $this->availablePath(
                $directory,
                $name
            );

            $file = fopen($filePath, 'x');

            if ($file === false) {
                throw new RuntimeException(
                    "Unable to create migration file [{$filePath}]."
                );
            }

            try {
                $this->write($file, $content . PHP_EOL, $filePath);
            } finally {
                fclose($file);
            }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }

        return [
            'filename' => $filename,
            'path' => $filePath,
        ];
    }

    private function assertTableName(string $table): void
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $table) !== 1) {
            throw new InvalidArgumentException(
                "Invalid table name [{$table}]."
            );
        }
    }

    private function migrationPath(?string $path): string
    {
        $path = $path ?? ROOT_PATH . '/database/migrations';

        if (!$this->isAbsolutePath($path)) {
            $path = ROOT_PATH
                . DIRECTORY_SEPARATOR
                . ltrim($path, '/\\');
        }

        return rtrim($path, '/\\');
    }

    private function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, DIRECTORY_SEPARATOR)
            || preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1;
    }

    private function ensureDirectory(string $directory): void
    {
        if (!is_dir($directory)
            && !mkdir($directory, 0775, true)
            && !is_dir($directory)) {
            throw new RuntimeException(
                "Unable to create migration directory [{$directory}]."
            );
        }

        if (!is_writable($directory)) {
            throw new RuntimeException(
                "Migration directory is not writable [{$directory}]."
            );
        }
    }

    private function availablePath(string $directory, string $name): array
    {
        do {
            $timestamp = (new DateTimeImmutable(
                'now',
                new DateTimeZone('UTC')
            ))->format('Ymd_His_u');

            $filename = "{$timestamp}_{$name}.php";
            $path = $directory . DIRECTORY_SEPARATOR . $filename;
        } while (is_file($path));

        return [$filename, $path];
    }

    private function createContent(string $table): string
    {
        $table = var_export($table, true);

        return <<<PHP
<?php

declare(strict_types=1);

use Bhitti\Database\Migration\Blueprint as Table;
use Bhitti\Database\Migration\Schema;

return [
    'up' => static function (): void {
        Schema::create({$table}, static function (Table \$table): void {
            \$table->id();
            \$table->timestamps();
        });
    },

    'down' => static function (): void {
        Schema::dropIfExists({$table});
    },
];
PHP;
    }

    private function alterContent(string $table): string
    {
        $table = var_export($table, true);

        return <<<PHP
<?php

declare(strict_types=1);

use Bhitti\Database\Migration\Blueprint as Table;
use Bhitti\Database\Migration\Schema;

return [
    'up' => static function (): void {
        Schema::table({$table}, static function (Table \$table): void {
            //
        });
    },

    'down' => static function (): void {
        Schema::table({$table}, static function (Table \$table): void {
            //
        });
    },
];
PHP;
    }

    /** @param resource $file */
    private function write($file, string $content, string $path): void
    {
        $length = strlen($content);
        $written = 0;

        while ($written < $length) {
            $bytes = fwrite(
                $file,
                substr($content, $written)
            );

            if ($bytes === false || $bytes === 0) {
                throw new RuntimeException(
                    "Unable to write migration file [{$path}]."
                );
            }

            $written += $bytes;
        }
    }
}
