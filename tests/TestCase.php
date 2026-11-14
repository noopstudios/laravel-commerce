<?php

namespace Noop\Commerce\Tests;

use BladeUI\Heroicons\BladeHeroiconsServiceProvider;
use BladeUI\Icons\BladeIconsServiceProvider;
use Filament\Actions\ActionsServiceProvider;
use Filament\FilamentServiceProvider;
use Filament\Forms\FormsServiceProvider;
use Filament\Infolists\InfolistsServiceProvider;
use Filament\Notifications\NotificationsServiceProvider;
use Filament\Schemas\SchemasServiceProvider;
use Filament\Support\SupportServiceProvider;
use Filament\Tables\TablesServiceProvider;
use Filament\Widgets\WidgetsServiceProvider;
use Livewire\LivewireServiceProvider;
use Noop\Commerce\CommerceServiceProvider;
use Noop\Commerce\Tests\Fixtures\TestPanelProvider;
use Noop\Commerce\Tests\Fixtures\User;
use Noop\Eupago\EupagoServiceProvider;
use Noop\Moloni\MoloniServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;
use RyanChandler\BladeCaptureDirective\BladeCaptureDirectiveServiceProvider;
use Spatie\LaravelSettings\LaravelSettingsServiceProvider;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [
            LaravelSettingsServiceProvider::class,
            MoloniServiceProvider::class,
            EupagoServiceProvider::class,
            CommerceServiceProvider::class,
            // Filament and what it needs, as a host app with a panel has them.
            LivewireServiceProvider::class,
            BladeCaptureDirectiveServiceProvider::class,
            BladeIconsServiceProvider::class,
            BladeHeroiconsServiceProvider::class,
            SupportServiceProvider::class,
            ActionsServiceProvider::class,
            FormsServiceProvider::class,
            InfolistsServiceProvider::class,
            NotificationsServiceProvider::class,
            SchemasServiceProvider::class,
            TablesServiceProvider::class,
            WidgetsServiceProvider::class,
            FilamentServiceProvider::class,
            TestPanelProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('k', 32)));
        $app['config']->set('auth.providers.users.model', User::class);
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);
    }

    protected function defineDatabaseMigrations(): void
    {
        // spatie/laravel-settings ships its migration as a stub, which loadMigrationsFrom() does not pick up.
        /** @var callable(): void $createSettingsTable */
        $createSettingsTable = [
            require __DIR__.'/../vendor/spatie/laravel-settings/database/migrations/create_settings_table.php.stub',
            'up',
        ];
        $createSettingsTable();

        // Every migration path the providers registered (this package, Moloni, EuPago), plus the test users table.
        /** @var list<string> $paths */
        $paths = app('migrator')->paths();

        $this->loadMigrationsFrom(['--path' => [...$paths, __DIR__.'/database/migrations'], '--realpath' => true]);
    }
}
