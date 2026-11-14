<?php

namespace Noop\Commerce\Tests\Host;

use Noop\Commerce\CommerceServiceProvider;
use Noop\Commerce\Tests\TestCase;
use Noop\Moloni\MoloniServiceProvider;
use Spatie\LaravelSettings\LaravelSettingsServiceProvider;

/**
 * A host app without Filament and without laravel-eupago: only Moloni (with the settings package it
 * needs) and this package are registered.
 */
abstract class WithoutFilamentTestCase extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [
            LaravelSettingsServiceProvider::class,
            MoloniServiceProvider::class,
            CommerceServiceProvider::class,
        ];
    }
}
