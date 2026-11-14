<?php

namespace Noop\Commerce\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static void ignoreOrderMigrations()
 *
 * @see \Noop\Commerce\Commerce
 */
class Commerce extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Noop\Commerce\Commerce::class;
    }
}
