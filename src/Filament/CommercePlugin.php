<?php

namespace Noop\Commerce\Filament;

use Filament\Contracts\Plugin;
use Filament\Panel;
use LogicException;
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

    public function boot(Panel $panel): void
    {
        // The order page uses Moloni's document actions, whose PDF link is a route MoloniPlugin registers.
        if (! $panel->hasPlugin('moloni')) {
            throw new LogicException('CommercePlugin requires MoloniPlugin in the same panel: add MoloniPlugin::make() to the panel plugins.');
        }
    }
}
