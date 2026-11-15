<?php

namespace Noop\Commerce;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Noop\Commerce\Console\RecoverCommand;
use Noop\Commerce\Eupago\EupagoBridge;
use Noop\Commerce\Models\Payment;
use Noop\Commerce\Policies\OrderPolicy;
use Noop\Commerce\Policies\PaymentPolicy;
use Noop\Moloni\Events\DocumentIssued;

class CommerceServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/commerce.php', 'commerce');

        $this->app->singleton(Commerce::class);

        EupagoBridge::register($this->app);

        // In register(): a host that translates before this provider boots would cache the JSON lines without ours.
        $this->loadJsonTranslationsFrom(__DIR__.'/../resources/lang');
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

        // Stable aliases, so the keys Moloni stores do not depend on class names.
        Relation::morphMap([
            'order' => Commerce::orderModel(),
            'payment' => Payment::class,
        ]);

        // Explicit, so the models stay guarded when the host guesses policy names its own way;
        // a policy the host registered for a model is kept.
        foreach ([Commerce::orderModel() => OrderPolicy::class, Payment::class => PaymentPolicy::class] as $model => $policy) {
            if (! array_key_exists($model, Gate::policies())) {
                Gate::policy($model, $policy);
            }
        }

        // An issued invoice lets the payments recorded before it get their receipt.
        Event::listen(DocumentIssued::class, [Billing::class, 'invoiceIssued']);

        if ($this->app->runningInConsole()) {
            $this->commands([RecoverCommand::class]);
        }

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            $schedule->command('commerce:recover')->everyFiveMinutes()->withoutOverlapping(10);
        });

        $this->publishes([
            __DIR__.'/../config/commerce.php' => config_path('commerce.php'),
        ], 'commerce-config');
    }
}
