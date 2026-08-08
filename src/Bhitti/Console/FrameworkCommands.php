<?php

declare(strict_types=1);

namespace Bhitti\Console;

use Bhitti\Console\Commands\CacheClearCommand;
use Bhitti\Console\Commands\ConfigCacheCommand;
use Bhitti\Console\Commands\MakeMigrationCommand;
use Bhitti\Console\Commands\MigrateCommand;
use Bhitti\Console\Commands\MigrateRollbackCommand;
use Bhitti\Console\Commands\MigrateStatusCommand;
use Bhitti\Console\Commands\MigrationAlterCommand;
use Bhitti\Console\Commands\MigrationCreateCommand;
use Bhitti\Console\Commands\RouteCacheCommand;

final class FrameworkCommands
{
    public static function all(): array
    {
        return [
            'migrate:create' => [
                'class' => MigrationCreateCommand::class,
                'description' => 'Create a new migration file.',
            ],
            'migrate:alter' => [
                'class' => MigrationAlterCommand::class,
                'description' => 'Update migration table.',
            ],

            'migrate' => [
                'class' => MigrateCommand::class,
                'description' => 'Run pending database migrations.',
            ],

            'migrate:rollback' => [
                'class' => MigrateRollbackCommand::class,
                'description' => 'Rollback database migrations.',
            ],

            'migrate:status' => [
                'class' => MigrateStatusCommand::class,
                'description' => 'Show database migration status.',
            ],

            'config:cache' => [
                'class' => ConfigCacheCommand::class,
                'description' => 'Create the configuration cache.',
            ],

            'route:cache' => [
                'class' => RouteCacheCommand::class,
                'description' => 'Create the route cache.',
            ],

            'cache:clear' => [
                'class' => CacheClearCommand::class,
                'description' => 'Clear application caches.',
            ],
        ];
    }
}