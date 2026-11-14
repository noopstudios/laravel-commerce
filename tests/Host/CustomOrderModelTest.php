<?php

use Illuminate\Database\Eloquent\Relations\Relation;
use Noop\Commerce\Commerce;
use Noop\Commerce\Models\OrderItem;
use Noop\Commerce\Models\PaymentAllocation;
use Noop\Commerce\Tests\Fixtures\CustomOrder;
use Noop\Commerce\Tests\Host\CustomOrderTestCase;

uses(CustomOrderTestCase::class);

it('uses the configured order model under the order morph alias', function () {
    $order = CustomOrder::create(['billing_name' => 'Ana']);
    $order->addItem(['name' => 'Consulting', 'unit_net_price' => '10', 'tax_rate' => 23]);
    $allocation = PaymentAllocation::factory()->for($order, 'payable')->create();

    expect(Commerce::orderModel())->toBe(CustomOrder::class)
        ->and(Relation::getMorphedModel('order'))->toBe(CustomOrder::class)
        ->and($allocation->payable_type)->toBe('order')
        ->and($allocation->payable()->first())->toBeInstanceOf(CustomOrder::class)
        ->and(OrderItem::query()->firstOrFail()->order)->toBeInstanceOf(CustomOrder::class);
});
