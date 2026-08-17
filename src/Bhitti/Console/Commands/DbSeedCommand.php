<?php

declare(strict_types=1);

namespace Bhitti\Console\Commands;

use Bhitti\Console\CommandInterface;
use Bhitti\Console\Input;
use Bhitti\Console\Output;
use InvalidArgumentException;
use RuntimeException;

final class DbSeedCommand implements CommandInterface
{
    public function handle(Input $input, Output $output): int
    {
        $path = ROOT_PATH . '/database/seeders';
        $filename = $input->option('filename');

        if ($filename === true) {
            throw new InvalidArgumentException('The --filename option requires a value.');
        }

        if ($filename !== null) {
            $this->run($path, $this->filename((string) $filename), $output);
            return 0;
        }

        $registry = $path . '/database.seeder.php';

        if (!is_file($registry)) {
            $output->line('Nothing to seed.');
            return 0;
        }

        $seeders = require $registry;

        if (!is_array($seeders)) {
            throw new RuntimeException('database.seeder.php must return an array.');
        }

        if ($seeders === []) {
            $output->line('Nothing to seed.');
            return 0;
        }

        foreach ($seeders as $filename) {
            $this->run($path, $this->filename((string) $filename), $output);
        }

        $output->success('Database seeded.');

        return 0;
    }

    private function run(string $path, string $filename, Output $output): void
    {
        $file = $path . '/' . $filename;

        if (!is_file($file)) {
            throw new RuntimeException("Seeder [{$filename}] not found.");
        }

        $seeder = require $file;

        if (!is_callable($seeder)) {
            throw new RuntimeException("Seeder [{$filename}] must return a callable.");
        }

        $seeder();
        $output->line('[SEED] ' . $filename);
    }

    private function filename(string $filename): string
    {
        $filename = trim($filename);

        if ($filename === '') {
            throw new InvalidArgumentException('Seeder filename cannot be empty.');
        }

        if (!str_ends_with($filename, '.seeder.php')) {
            $filename .= '.seeder.php';
        }

        if (basename($filename) !== $filename || !preg_match('/^[A-Za-z0-9_-]+\.seeder\.php$/', $filename)) {
            throw new InvalidArgumentException("Invalid seeder filename [{$filename}].");
        }

        return $filename;
    }
}
