<?php

namespace Noop\Commerce;

use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\ServiceProvider;
use Noop\Commerce\Models\Payment;

class CommerceServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/commerce.php', 'commerce');

        $this->app->singleton(Commerce::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        // After every provider has booted, so Commerce::ignoreOrderMigrations() works from the host's register() or boot().
        $this->app->booted(function () {
            if (! Commerce::$ignoreOrderMigrations) {
                $this->loadMigrationsFrom(__DIR__.'/../database/migrations/orders');
            }
        });

        $this->loadJsonTranslationsFrom(__DIR__.'/../resources/lang');

        // Stable aliases, so the keys Moloni stores do not depend on class names.
        Relation::morphMap([
            'order' => Commerce::orderModel(),
            'payment' => Payment::class,
        ]);

        $this->publishes([
            __DIR__.'/../config/commerce.php' => config_path('commerce.php'),
        ], 'commerce-config');
    }
}
