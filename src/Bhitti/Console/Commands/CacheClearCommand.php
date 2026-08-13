<?php

declare(strict_types=1);

namespace Bhitti\Console\Commands;

use Bhitti\Cache\Cache;
use Bhitti\Console\Input;
use Bhitti\Console\Output;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Throwable;

final class CacheClearCommand extends MigrationCommand
{
    public function handle(Input $input, Output $output): int
    {
        $cleanDirectories = [
            STORAGE_PATH . '/cache/rate-limit',
            STORAGE_PATH . '/cache/file-cache',
        ];

        $cleanFiles = [
            STORAGE_PATH . '/cache/config.cache.php',
            STORAGE_PATH . '/cache/route.cache.php',
        ];

        $protectedFiles = [
            '.gitkeep',
            'README.md',
        ];

        $failures = [];
        $warnings = [];

        $deletedFiles = 0;
        $deletedDirectories = 0;

        /*
        |--------------------------------------------------------------------------
        | Clear Active Cache Store
        |--------------------------------------------------------------------------
        */
        $storeCleared = false;
        $driver = 'unknown';

        try {
            $driver = strtolower(
                trim((string) config('cache.driver', 'file'))
            );

            Cache::flush();

            $storeCleared = true;

            if ($driver === 'apcu') {
                $warnings[] = 'APCu was cleared only for the CLI process. PHP-FPM or Apache APCu storage may require a web-context clear operation or process restart.';
            }
        } catch (Throwable $exception) {
            $failures[] = sprintf(
                'Unable to clear the active [%s] cache store: %s',
                $driver,
                $exception->getMessage()
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Clear Generated Cache Files
        |--------------------------------------------------------------------------
        */
        foreach ($cleanFiles as $file) {
            if (!is_file($file) && !is_link($file)) {
                continue;
            }

            if (@unlink($file)) {
                $deletedFiles++;
            } else {
                $failures[] = "Unable to delete file: {$file}";
            }
        }

        /*
        |--------------------------------------------------------------------------
        | Clear Allowed Cache Directories
        |--------------------------------------------------------------------------
        |
        | Only directories explicitly listed above are cleaned.
        | The root directory itself is preserved.
        |
        */
        foreach ($cleanDirectories as $directory) {
            if (!is_dir($directory)) {
                continue;
            }

            try {
                $iterator = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator(
                        $directory,
                        FilesystemIterator::SKIP_DOTS
                    ),
                    RecursiveIteratorIterator::CHILD_FIRST
                );

                foreach ($iterator as $item) {
                    $path = $item->getPathname();
                    $filename = $item->getFilename();

                    if (in_array($filename, $protectedFiles, true)) {
                        continue;
                    }

                    if ($item->isLink() || $item->isFile()) {
                        if (@unlink($path)) {
                            $deletedFiles++;
                        } else {
                            $failures[] =
                                "Unable to delete file: {$path}";
                        }

                        continue;
                    }

                    if (!$item->isDir()) {
                        continue;
                    }

                    /*
                     * Keep non-empty directories.
                     * This also preserves directories containing
                     * protected files such as .gitkeep.
                     */
                    $contents = @scandir($path);

                    if ($contents === false) {
                        $failures[] =
                            "Unable to read directory: {$path}";
                        continue;
                    }

                    if (array_diff($contents, ['.', '..']) !== []) {
                        continue;
                    }

                    if (@rmdir($path)) {
                        $deletedDirectories++;
                    } elseif (is_dir($path)) {
                        $failures[] =
                            "Unable to delete directory: {$path}";
                    }
                }
            } catch (Throwable $exception) {
                $failures[] =
                    "Unable to clean directory [{$directory}]: "
                    . $exception->getMessage();
            }
        }

        /*
        |--------------------------------------------------------------------------
        | Output
        |--------------------------------------------------------------------------
        */
        if ($storeCleared) {
            $output->success("Application cache store cleared: {$driver}");
        }

        $output->line("  Deleted cache files: {$deletedFiles}");
        $output->line("  Deleted cache directories: {$deletedDirectories}");

        foreach ($warnings as $warning) {
            $output->warning($warning);
        }

        if ($failures !== []) {
            $output->warning('Cache clear completed with errors.' . implode("\n- ", $failures));

            return 1;
        }

        return 0;
    }
}