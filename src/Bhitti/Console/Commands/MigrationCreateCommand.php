<?php

declare(strict_types=1);

namespace Bhitti\Console\Commands;

use Bhitti\Console\CommandInterface;
use Bhitti\Console\Input;
use Bhitti\Console\MigrationFileGenerator;
use Bhitti\Console\Output;
use InvalidArgumentException;

final class MigrationCreateCommand implements CommandInterface
{
    public function __construct(
        private readonly MigrationFileGenerator $generator
    ) {
    }

    public function handle(Input $input, Output $output): int
    {
        $table = trim((string) $input->argument(0, ''));

        if ($table === '') {
            throw new InvalidArgumentException(
                'Usage: php run migrate:create table [--path=path]'
            );
        }

        $migration = $this->generator->create(
            $table,
            $this->path($input)
        );

        $output->success(
            "Created migrate: {$migration['filename']}"
        );
        $output->line("Path: {$migration['path']}");

        return 0;
    }

    private function path(Input $input): ?string
    {
        $path = $input->option('path');

        if ($path === null) {
            return null;
        }

        if ($path === true) {
            throw new InvalidArgumentException(
                'The --path option requires a value.'
            );
        }

        $path = trim((string) $path);

        if ($path === '') {
            throw new InvalidArgumentException(
                'The --path option cannot be empty.'
            );
        }

        return $path;
    }
}
