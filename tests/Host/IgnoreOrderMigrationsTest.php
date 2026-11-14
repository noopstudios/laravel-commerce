<?php

use Illuminate\Support\Facades\Schema;
use Noop\Commerce\Tests\Host\IgnoreOrderMigrationsTestCase;

uses(IgnoreOrderMigrationsTestCase::class);

it('leaves the orders tables to the host but still creates the payments tables', function () {
    expect(Schema::hasTable('orders'))->toBeFalse()
        ->and(Schema::hasTable('order_items'))->toBeFalse()
        ->and(Schema::hasTable('payments'))->toBeTrue()
        ->and(Schema::hasTable('payment_allocations'))->toBeTrue();
});
