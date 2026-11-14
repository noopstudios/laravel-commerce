<?php

use Illuminate\Support\Facades\Event;
use Noop\Commerce\Enums\BillingFlow;
use Noop\Commerce\Enums\OrderStatus;
use Noop\Commerce\Enums\PaymentMethod;
use Noop\Commerce\Enums\PaymentSource;
use Noop\Commerce\Events\PayableSettled;
use Noop\Commerce\Exceptions\CommerceException;
use Noop\Commerce\Facades\Commerce;
use Noop\Commerce\Models\Order;
use Noop\Commerce\Models\Payment;
use Noop\Commerce\Models\PaymentAllocation;
use Noop\Moloni\Enums\DocumentStatus;
use Noop\Moloni\Models\InvoiceDocument;
use Noop\Moloni\Models\InvoiceDocumentLink;

/**
 * A confirmed order of 12.30 (10.00 + 23% VAT), pending payment.
 */
function ledgerOrder(BillingFlow $flow = BillingFlow::InvoiceFirst): Order
{
    $order = Order::factory()->create(['billing_flow' => $flow]);
    $order->addItem(['name' => 'Consulting', 'unit_net_price' => '10', 'tax_rate' => 23]);
    $order->confirm();

    return $order;
}

function ledgerPay(Order $order, int $cents, ?string $key = null): Payment
{
    return Commerce::recordPayment([[$order, $cents]], PaymentMethod::Transfer, now(), idempotencyKey: $key);
}

/**
 * The order's issued invoice of 12.30, for one Moloni customer with a VAT number.
 *
 * @param  array<string, mixed>  $attributes
 */
function ledgerInvoice(Order $order, array $attributes = []): InvoiceDocument
{
    return InvoiceDocument::factory()->invoice()->issued()->create([
        'invoiceable_type' => 'order',
        'invoiceable_id' => $order->id,
        'expected_total_cents' => 1230,
        'customer_id' => 301,
        'customer_vat' => '509999999',
        ...$attributes,
    ]);
}

/**
 * A payment that arrived and was not applied: under review, nothing allocated.
 */
function ledgerReviewPayment(Order $order, int $cents): Payment
{
    return Payment::factory()->for($order, 'payable')->create([
        'source' => PaymentSource::Eupago,
        'external_id' => 'trid-'.$order->id,
        'amount_cents' => $cents,
        'needs_review' => true,
        'review_reason' => 'late',
    ]);
}

function ledgerRefused(Closure $call): CommerceException
{
    try {
        $call();
    } catch (CommerceException $e) {
        return $e;
    }

    throw new RuntimeException('Expected a CommerceException.');
}

it('refuses an amount above the due and writes nothing', function () {
    $order = ledgerOrder();

    ledgerRefused(fn () => ledgerPay($order, 1231));

    expect(Payment::query()->count())->toBe(0)
        ->and(PaymentAllocation::query()->count())->toBe(0)
        ->and($order->refresh()->status)->toBe(OrderStatus::PendingPayment);
});

it('re-reads the due under the lock, so a stale instance cannot pay above it', function () {
    $order = ledgerOrder();
    $stale = Order::query()->findOrFail($order->id);
    ledgerPay($order, 1000);

    $e = ledgerRefused(fn () => ledgerPay($stale, 500));

    expect($e->getMessage())->toContain('more than the 230')
        ->and(Payment::query()->count())->toBe(1)
        ->and($stale->refresh()->status)->toBe(OrderStatus::PartiallyPaid)
        ->and(Commerce::dueCents($order))->toBe(230);
});

it('refuses a draft order', function () {
    $order = Order::factory()->create(['billing_flow' => BillingFlow::InvoiceFirst]);
    $order->addItem(['name' => 'Consulting', 'unit_net_price' => '10', 'tax_rate' => 23]);

    ledgerRefused(fn () => ledgerPay($order, 1230));

    expect($order->refresh()->status)->toBe(OrderStatus::Draft)
        ->and(Payment::query()->count())->toBe(0);
});

it('requires the exact due in circuit A', function (int $cents) {
    $order = ledgerOrder(BillingFlow::PayFirst);

    ledgerRefused(fn () => ledgerPay($order, $cents));

    expect($order->refresh()->status)->toBe(OrderStatus::PendingPayment)
        ->and(Payment::query()->count())->toBe(0);
})->with([1229, 1231]);

it('pays a circuit A order in full', function () {
    $order = ledgerOrder(BillingFlow::PayFirst);

    ledgerPay($order, 1230);

    expect($order->status)->toBe(OrderStatus::Paid)
        ->and($order->paid_at)->not->toBeNull();
});

it('settles a circuit B order in parts, dispatching PayableSettled once, when nothing is left', function () {
    Event::fake([PayableSettled::class]);
    $order = ledgerOrder();

    ledgerPay($order, 1000);

    expect($order->status)->toBe(OrderStatus::PartiallyPaid)
        ->and($order->paid_at)->toBeNull();
    Event::assertNotDispatched(PayableSettled::class);

    ledgerPay($order, 230);

    expect($order->status)->toBe(OrderStatus::Paid)
        ->and($order->paid_at)->not->toBeNull();
    Event::assertDispatchedTimes(PayableSettled::class, 1);
});

it('returns the payment already recorded under the same key', function () {
    $order = ledgerOrder(BillingFlow::PayFirst);

    $first = ledgerPay($order, 1230, 'transfer-42');
    $again = ledgerPay($order, 1230, 'transfer-42');

    expect($again->id)->toBe($first->id)
        ->and(Payment::query()->count())->toBe(1)
        ->and(PaymentAllocation::query()->count())->toBe(1);
});

it('stops counting a voided payment and reopens the due', function () {
    $order = ledgerOrder();
    $first = ledgerPay($order, 1000);
    $second = ledgerPay($order, 230);

    Commerce::voidPayment($second, 'Bounced');

    expect($order->refresh()->status)->toBe(OrderStatus::PartiallyPaid)
        ->and($order->paid_at)->toBeNull()
        ->and(Commerce::dueCents($order))->toBe(230)
        ->and($second->voided_at)->not->toBeNull()
        ->and($second->void_reason)->toBe('Bounced');

    Commerce::voidPayment($first, 'Bounced');

    expect($order->refresh()->status)->toBe(OrderStatus::PendingPayment)
        ->and(Commerce::dueCents($order))->toBe(1230);
});

it('reopens a paid circuit A order when its payment is voided', function () {
    $order = ledgerOrder(BillingFlow::PayFirst);
    $payment = ledgerPay($order, 1230);

    Commerce::voidPayment($payment, 'Recorded on the wrong order');

    expect($order->refresh()->status)->toBe(OrderStatus::PendingPayment)
        ->and($order->paid_at)->toBeNull();
});

it('refuses to void a payment that did not come in by hand', function () {
    $order = ledgerOrder();
    $payment = ledgerReviewPayment($order, 1230);
    Commerce::allocate($payment, $order);

    ledgerRefused(fn () => Commerce::voidPayment($payment, 'Bounced'));

    expect($payment->refresh()->voided_at)->toBeNull()
        ->and($order->refresh()->status)->toBe(OrderStatus::Paid);
});

it('refuses to void a payment a live document depends on', function (BillingFlow $flow, Closure $document) {
    $order = ledgerOrder($flow);
    $payment = ledgerPay($order, 1230);
    $document($order, $payment);

    ledgerRefused(fn () => Commerce::voidPayment($payment, 'Bounced'));

    expect($payment->refresh()->voided_at)->toBeNull()
        ->and($order->refresh()->status)->toBe(OrderStatus::Paid);
})->with([
    'its receipt' => [BillingFlow::InvoiceFirst, fn (Order $order, Payment $payment) => InvoiceDocument::factory()->receipt()->issued()->create([
        'invoiceable_type' => 'payment', 'invoiceable_id' => $payment->id,
    ])],
    'the invoice-receipt of a circuit A order' => [BillingFlow::PayFirst, fn (Order $order) => InvoiceDocument::factory()->invoiceReceipt()->create([
        'invoiceable_type' => 'order', 'invoiceable_id' => $order->id,
    ])],
]);

it('reopens an expired order with a payment under review and clears the review', function () {
    Event::fake([PayableSettled::class]);
    $order = ledgerOrder(BillingFlow::PayFirst);
    $order->onExpired();
    $payment = ledgerReviewPayment($order, 1230);

    $allocation = Commerce::allocate($payment, $order);

    expect($allocation->amount_cents)->toBe(1230)
        ->and($order->status)->toBe(OrderStatus::Paid)
        ->and($payment->needs_review)->toBeFalse();
    Event::assertDispatchedTimes(PayableSettled::class, 1);
});

it('keeps a payment under review while part of it is left', function () {
    $order = ledgerOrder();
    $payment = ledgerReviewPayment($order, 2000);

    $allocation = Commerce::allocate($payment, $order);

    expect($allocation->amount_cents)->toBe(1230)
        ->and($order->status)->toBe(OrderStatus::Paid)
        ->and($payment->needs_review)->toBeTrue();
});

it('refuses to allocate above the due, above what is left, or a payment not under review', function (int $paid, ?int $cents, bool $review) {
    $order = ledgerOrder();
    $payment = ledgerReviewPayment($order, $paid);
    $payment->forceFill(['needs_review' => $review])->save();

    ledgerRefused(fn () => Commerce::allocate($payment, $order, $cents));

    expect(PaymentAllocation::query()->count())->toBe(0)
        ->and($order->refresh()->status)->toBe(OrderStatus::PendingPayment)
        ->and($payment->refresh()->needs_review)->toBe($review);
})->with([
    'above the due' => [2000, 1231, true],
    'above what is left' => [500, 600, true],
    'not under review' => [1230, null, false],
]);

it('takes live credit notes off the due, and not voided or failed ones', function () {
    $order = ledgerOrder();
    $invoice = ledgerInvoice($order);

    foreach ([DocumentStatus::Issued, DocumentStatus::Voided, DocumentStatus::Failed] as $status) {
        $note = InvoiceDocument::factory()->creditNote()->create(['status' => $status, 'expected_total_cents' => 300]);
        InvoiceDocumentLink::create(['document_id' => $note->id, 'associated_document_id' => $invoice->id, 'amount_cents' => 300]);
    }

    expect(Commerce::dueCents($order))->toBe(930);

    ledgerRefused(fn () => ledgerPay($order, 931));
    ledgerPay($order, 930);

    expect($order->status)->toBe(OrderStatus::Paid);
});

it('records one payment for several issued invoices of one customer', function () {
    $first = ledgerOrder();
    $second = ledgerOrder();
    ledgerInvoice($first);
    ledgerInvoice($second);

    $payment = Commerce::recordPayment([[$first, 1230], [$second, 500]], PaymentMethod::Transfer, now());

    expect($payment->amount_cents)->toBe(1730)
        ->and($payment->payable_id)->toBe($first->id)
        ->and($payment->allocations()->orderBy('id')->pluck('amount_cents', 'payable_id')->all())->toBe([$first->id => 1230, $second->id => 500])
        ->and($first->status)->toBe(OrderStatus::Paid)
        ->and($second->status)->toBe(OrderStatus::PartiallyPaid);
});

it('refuses a joint payment one receipt cannot settle, and writes nothing', function (Closure $spoil) {
    $first = ledgerOrder();
    $second = ledgerOrder();
    ledgerInvoice($first);
    $spoil($second);

    ledgerRefused(fn () => Commerce::recordPayment([[$first, 1230], [$second, 1230]], PaymentMethod::Transfer, now()));

    expect(Payment::query()->count())->toBe(0)
        ->and(PaymentAllocation::query()->count())->toBe(0)
        ->and($first->refresh()->status)->toBe(OrderStatus::PendingPayment)
        ->and($second->refresh()->status)->toBe(OrderStatus::PendingPayment);
})->with([
    'another Moloni customer' => [fn (Order $order) => ledgerInvoice($order, ['customer_id' => 302])],
    'a final consumer' => [fn (Order $order) => ledgerInvoice($order, ['customer_vat' => null])],
    'an invoice not issued yet' => [fn (Order $order) => ledgerInvoice($order, ['status' => DocumentStatus::Pending])],
    'a circuit A order' => [function (Order $order) {
        $order->forceFill(['billing_flow' => BillingFlow::PayFirst])->save();
        ledgerInvoice($order);
    }],
]);
