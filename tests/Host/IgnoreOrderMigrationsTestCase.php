<?php

namespace Noop\Commerce\Tests\Host;

use Noop\Commerce\Commerce;
use Noop\Commerce\Tests\TestCase;

/**
 * A host that owns the orders tables (level 3): only the migrations the providers register run.
 */
abstract class IgnoreOrderMigrationsTestCase extends TestCase
{
    protected function setUp(): void
    {
        Commerce::ignoreOrderMigrations();

        parent::setUp();
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        Commerce::$ignoreOrderMigrations = false;
    }

    protected function defineDatabaseMigrations(): void
    {
        /** @var callable(): void $createSettingsTable */
        $createSettingsTable = [
            require __DIR__.'/../../vendor/spatie/laravel-settings/database/migrations/create_settings_table.php.stub',
            'up',
        ];
        $createSettingsTable();

        /** @var list<string> $paths */
        $paths = app('migrator')->paths();

        $this->loadMigrationsFrom(['--path' => $paths, '--realpath' => true]);
    }
}
