<?php

namespace Noop\Commerce;

use Noop\Commerce\Models\Order;

class Commerce
{
    public static bool $ignoreOrderMigrations = false;

    /**
     * Skip the orders and order_items migrations, for hosts that own those tables.
     */
    public static function ignoreOrderMigrations(): void
    {
        static::$ignoreOrderMigrations = true;
    }

    /**
     * The order model the host configured (commerce.models.order).
     *
     * @return class-string<Order>
     */
    public static function orderModel(): string
    {
        /** @var class-string<Order> */
        return config('commerce.models.order');
    }
}
