<?php

namespace Noop\Commerce;

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
}
