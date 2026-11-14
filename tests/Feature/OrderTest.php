<?php

use Illuminate\Support\Facades\Event;
use Noop\Commerce\Enums\BillingFlow;
use Noop\Commerce\Enums\OrderStatus;
use Noop\Commerce\Events\PayableExpired;
use Noop\Commerce\Events\PayableSettled;
use Noop\Commerce\Exceptions\CommerceException;
use Noop\Commerce\Models\Order;
use Noop\Commerce\Models\OrderItem;
use Noop\Commerce\Models\Payment;
use Noop\Commerce\Models\PaymentAllocation;
use Noop\Commerce\Tests\Fixtures\Product;
use Noop\Commerce\Tests\Fixtures\User;

/**
 * A confirmed order of 12.30 (10.00 + 23% VAT), left in the given status.
 */
function orderIn(OrderStatus $status, BillingFlow $flow = BillingFlow::PayFirst): Order
{
    $order = Order::factory()->create(['billing_flow' => $flow]);
    $order->addItem(['name' => 'Consulting', 'unit_net_price' => '10', 'tax_rate' => 23]);
    $order->forceFill(['status' => $status])->save();

    return $order;
}

it('never mass assigns the status, paid date, number, token or totals', function () {
    $protected = [
        'status' => OrderStatus::Paid, 'paid_at' => now(), 'number' => 'X-1', 'public_token' => 'token',
        'subtotal_cents' => 100, 'tax_cents' => 23, 'total_cents' => 123,
    ];

    $order = Order::create(['billing_name' => 'Ana'] + $protected)->refresh();
    $order->update($protected);
    $order->fill($protected)->save();
    $order->refresh();

    expect($order->status)->toBe(OrderStatus::Draft)
        ->and($order->paid_at)->toBeNull()
        ->and($order->number)->toBe('ORD-'.$order->id)
        ->and($order->public_token)->not->toBe('token')
        ->and($order->total_cents)->toBe(0);
});

it('confirms a draft with lines into pending payment', function () {
    $order = Order::factory()->create();
    $order->addItem(['name' => 'Consulting', 'unit_net_price' => '10', 'tax_rate' => 23], 2);

    $order->confirm();

    expect($order->status)->toBe(OrderStatus::PendingPayment)
        ->and($order->refresh()->status)->toBe(OrderStatus::PendingPayment)
        ->and($order->total_cents)->toBe(2460)
        ->and($order->paid_at)->toBeNull();
});

it('refuses to confirm an order without lines', function () {
    Order::factory()->create()->confirm();
})->throws(CommerceException::class, 'has no lines');

it('refuses to confirm twice, even from a stale instance', function () {
    $order = Order::factory()->create();
    $order->addItem(['name' => 'Consulting', 'unit_net_price' => '10', 'tax_rate' => 23]);
    $stale = Order::query()->findOrFail($order->id);

    $order->confirm();
    $stale->confirm();
})->throws(CommerceException::class, 'is not a draft');

it('marks a zero-total order paid on confirm, with no payment', function () {
    Event::fake([PayableSettled::class]);
    $order = Order::factory()->create();
    $order->addItem(['name' => 'Voucher', 'unit_net_price' => '0', 'tax_rate' => 0, 'exemption_reason' => 'M07']);

    $order->confirm();

    expect($order->refresh()->status)->toBe(OrderStatus::Paid)
        ->and($order->paid_at)->not->toBeNull()
        ->and(Payment::query()->count())->toBe(0);
    Event::assertDispatched(PayableSettled::class, fn (PayableSettled $event) => $event->payable->is($order));
});

it('cancels from draft, pending payment or expired', function (OrderStatus $from) {
    $order = orderIn($from);

    $order->cancel();

    expect($order->refresh()->status)->toBe(OrderStatus::Cancelled);
})->with([OrderStatus::Draft, OrderStatus::PendingPayment, OrderStatus::Expired]);

it('refuses to cancel a paid, partially paid or cancelled order', function (OrderStatus $from) {
    orderIn($from)->cancel();
})->with([OrderStatus::Paid, OrderStatus::PartiallyPaid, OrderStatus::Cancelled])
    ->throws(CommerceException::class);

it('refuses to cancel an order with a payment allocated, until that payment is voided', function () {
    $order = orderIn(OrderStatus::PendingPayment);
    $payment = Payment::factory()->create(['amount_cents' => 500]);
    PaymentAllocation::factory()->for($payment)->for($order, 'payable')->create(['amount_cents' => 500]);

    expect(fn () => $order->cancel())->toThrow(CommerceException::class, 'payments allocated');

    $payment->forceFill(['voided_at' => now()])->save();
    $order->cancel();

    expect($order->refresh()->status)->toBe(OrderStatus::Cancelled);
});

it('maps the amount due to the status, reopening expired and cancelled orders', function (OrderStatus $from, int $due, OrderStatus $to) {
    $order = orderIn($from);
    $order->forceFill(['paid_at' => $from === OrderStatus::Paid ? now() : null])->save();

    $order->onDueChanged($due);
    $order->refresh();

    expect($order->status)->toBe($to)
        ->and($order->paid_at === null)->toBe($to !== OrderStatus::Paid);
})->with([
    'paid in full' => [OrderStatus::PendingPayment, 0, OrderStatus::Paid],
    'paid in part' => [OrderStatus::PendingPayment, 500, OrderStatus::PartiallyPaid],
    'payment voided' => [OrderStatus::Paid, 1230, OrderStatus::PendingPayment],
    'part voided' => [OrderStatus::Paid, 500, OrderStatus::PartiallyPaid],
    'late payment on expired' => [OrderStatus::Expired, 0, OrderStatus::Paid],
    'late payment on cancelled' => [OrderStatus::Cancelled, 500, OrderStatus::PartiallyPaid],
]);

it('refuses a due change on a draft', function () {
    orderIn(OrderStatus::Draft)->onDueChanged(0);
})->throws(LogicException::class);

it('expires only circuit A orders pending payment', function (BillingFlow $flow, OrderStatus $from, OrderStatus $to) {
    Event::fake([PayableExpired::class]);
    $order = orderIn($from, $flow);

    $order->onExpired();

    expect($order->refresh()->status)->toBe($to);
    $to === OrderStatus::Expired
        ? Event::assertDispatched(PayableExpired::class, fn (PayableExpired $event) => $event->payable->is($order))
        : Event::assertNotDispatched(PayableExpired::class);
})->with([
    'A pending' => [BillingFlow::PayFirst, OrderStatus::PendingPayment, OrderStatus::Expired],
    'A partially paid' => [BillingFlow::PayFirst, OrderStatus::PartiallyPaid, OrderStatus::PartiallyPaid],
    'B pending' => [BillingFlow::InvoiceFirst, OrderStatus::PendingPayment, OrderStatus::PendingPayment],
]);

it('freezes the lines once the order is confirmed', function () {
    $order = Order::factory()->create();
    $item = $order->addItem(['name' => 'Consulting', 'unit_net_price' => '10', 'tax_rate' => 23]);
    $stale = Order::query()->findOrFail($order->id);
    $order->confirm();

    expect(fn () => $order->addItem(['name' => 'Extra', 'unit_net_price' => '1', 'tax_rate' => 23]))->toThrow(CommerceException::class)
        ->and(fn () => $stale->addItem(['name' => 'Extra', 'unit_net_price' => '1', 'tax_rate' => 23]))->toThrow(CommerceException::class)
        ->and(fn () => OrderItem::factory()->create(['order_id' => $order->id]))->toThrow(CommerceException::class)
        ->and(fn () => $item->update(['unit_net_price' => '1']))->toThrow(CommerceException::class)
        ->and(fn () => $item->delete())->toThrow(CommerceException::class);

    expect($order->items()->count())->toBe(1)
        ->and($item->refresh()->total_cents)->toBe(1230)
        ->and($order->refresh()->total_cents)->toBe(1230);
});

it('charges exactly what Moloni will invoice, rounding each line', function () {
    $buyer = User::query()->create(['name' => 'Ana', 'email' => 'ana@example.test', 'password' => 'x']);
    $order = Order::factory()->create(['customer_reference' => 'PO-7']);
    $order->buyer()->associate($buyer)->save();

    // 1.005 rounds up to 1.01 on each line: 3.03, not the 3.02 of rounding the sum.
    foreach (range(1, 3) as $i) {
        $order->addItem(['name' => "Pen {$i}", 'unit_net_price' => '1.00500', 'tax_rate' => 23]);
    }
    $order->addItem(['name' => 'Hours', 'unit_net_price' => '3.33333', 'tax_rate' => 13], 2.5);
    $order->addItem(['name' => 'Book', 'unit_net_price' => '19.99', 'tax_rate' => 6], 2);
    $order->addItem(['name' => 'Export', 'unit_net_price' => '50', 'tax_rate' => 0, 'exemption_reason' => 'M07']);
    $order->confirm();

    $invoice = $order->toInvoiceData();

    expect($order->subtotal_cents)->toBe(303 + 833 + 3998 + 5000)
        ->and($order->tax_cents)->toBe(69 + 108 + 240)
        ->and($order->totalCents())->toBe(10551)
        ->and($invoice->netCents())->toBe($order->subtotal_cents)
        ->and($invoice->taxCents())->toBe($order->tax_cents)
        ->and($invoice->totalCents())->toBe($order->totalCents())
        ->and($invoice->lines[3]->unitNetPrice)->toBe('3.33333')
        ->and($invoice->lines[5]->exemptionReason)->toBe('M07')
        ->and($invoice->yourReference)->toBe('PO-7')
        ->and($invoice->customer->key)->toBe('U'.$buyer->id)
        ->and($invoice->customer->createdAt?->eq($buyer->created_at))->toBeTrue();
});

it('keeps a guest order and a buyer whose type starts with O under different customer keys', function () {
    $guest = Order::factory()->create();
    $organization = Order::factory()->create(['buyer_type' => 'App\\Models\\Organization', 'buyer_id' => $guest->id]);

    expect($guest->customerKey())->toBe('0'.$guest->id)
        ->and($organization->customerKey())->toBe('O'.$guest->id)
        ->and($guest->customerKey())->not->toBe($organization->customerKey());
});

it('does not move a line out of a confirmed order', function () {
    $confirmed = Order::factory()->create();
    $item = $confirmed->addItem(['name' => 'Consulting', 'unit_net_price' => '10', 'tax_rate' => 23]);
    $confirmed->confirm();
    $draft = Order::factory()->create();

    expect(fn () => $item->update(['order_id' => $draft->id]))->toThrow(CommerceException::class)
        ->and($item->refresh()->order_id)->toBe($confirmed->id);
});

it('takes the quantity from the argument, else from the item, else 1', function () {
    $order = Order::factory()->create();
    $line = ['name' => 'Consulting', 'unit_net_price' => '10', 'tax_rate' => 23];

    $order->addItem($line + ['quantity' => 3]);
    expect($order->total_cents)->toBe(3690);

    $order->addItem($line + ['quantity' => 3], 2);
    expect($order->total_cents)->toBe(3690 + 2460);

    $order->addItem($line);
    expect($order->total_cents)->toBe(3690 + 2460 + 1230);
});

it('builds the line of a purchasable model from toOrderItem and links the model', function () {
    $product = Product::query()->create(['name' => 'Workshop', 'email' => 'w@example.test', 'password' => 'x']);
    $order = Order::factory()->create();

    $line = $order->addItem($product, 2);

    expect($line->name)->toBe('Workshop')
        ->and($line->reference)->toBe('WS-1')
        ->and($line->total_cents)->toBe(4920)
        ->and($line->purchasable_id)->toBe($product->id)
        ->and($line->purchasable_type)->toBe($product->getMorphClass())
        ->and($line->purchasable)->toBeInstanceOf(Product::class);
});

it('freezes the billing flow, currency and payment terms once the order leaves draft', function () {
    $order = Order::factory()->create();
    $order->addItem(['name' => 'Consulting', 'unit_net_price' => '10', 'tax_rate' => 23]);

    $order->update(['billing_flow' => BillingFlow::InvoiceFirst, 'payment_terms_days' => 30]);
    $order->confirm();

    foreach (['billing_flow' => BillingFlow::PayFirst, 'currency' => 'USD', 'payment_terms_days' => 60] as $field => $value) {
        expect(fn () => $order->update([$field => $value]))->toThrow(CommerceException::class);
        $order->refresh();
    }

    $order->update(['notes' => 'Still editable']);
    expect($order->refresh()->billing_flow)->toBe(BillingFlow::InvoiceFirst)
        ->and($order->currency)->toBe('EUR');
});

it('confirms with totals recomputed from the lines, whatever the stored columns say', function () {
    $order = Order::factory()->create();
    $line = $order->addItem(['name' => 'Consulting', 'unit_net_price' => '10', 'tax_rate' => 23]);
    OrderItem::query()->whereKey($line->id)->toBase()->update(['subtotal_cents' => 1, 'tax_cents' => 1, 'total_cents' => 5]);

    $order->confirm();

    expect($order->total_cents)->toBe(1230)
        ->and($order->total_cents)->toBe($order->toInvoiceData()->totalCents())
        ->and($order->refresh()->total_cents)->toBe(1230);
});
