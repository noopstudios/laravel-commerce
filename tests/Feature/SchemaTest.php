<?php

use Noop\Commerce\Models\Order;

it('runs the migrations and numbers a new order', function () {
    $order = Order::factory()->create();

    expect($order->number)->toBe('ORD-'.$order->id)
        ->and($order->public_token)->not->toBeEmpty();
});
