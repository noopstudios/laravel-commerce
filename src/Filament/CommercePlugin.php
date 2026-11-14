<?php

namespace Noop\Commerce\Filament;

use Filament\Contracts\Plugin;
use Filament\Panel;
use Noop\Commerce\Filament\Resources\Orders\OrderResource;
use Noop\Commerce\Filament\Resources\Payments\PaymentResource;

/**
 * Adds the orders and payments to a panel: `->plugins([CommercePlugin::make()])`. Access follows
 * OrderPolicy and PaymentPolicy (registered by the package).
 */
class CommercePlugin implements Plugin
{
    public static function make(): static
    {
        return app(static::class);
    }

    public function getId(): string
    {
        return 'commerce';
    }

    public function register(Panel $panel): void
    {
        $panel->resources([OrderResource::class, PaymentResource::class]);
    }

    public function boot(Panel $panel): void {}
}
