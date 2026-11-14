<?php

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Noop\Commerce\Billing;
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
use Noop\Commerce\Tests\Fixtures\User;
use Noop\Eupago\Enums\AttemptStatus;
use Noop\Eupago\Enums\EventStatus;
use Noop\Eupago\Facades\Eupago;
use Noop\Eupago\Models\PaymentAttempt;
use Noop\Moloni\Data\PaymentData;
use Noop\Moloni\Enums\DocumentStatus;
use Noop\Moloni\Enums\DocumentType;
use Noop\Moloni\Enums\FailureType;
use Noop\Moloni\Exceptions\MoloniException;
use Noop\Moloni\Facades\Moloni;
use Noop\Moloni\Models\InvoiceDocument;
use Noop\Moloni\Models\InvoiceDocumentLink;
use Noop\Moloni\Moloni as MoloniManager;

/**
 * A confirmed order of 12.30 (10.00 + 23% VAT) of a business customer, pending payment.
 *
 * @param  array<string, mixed>  $attributes
 */
function billingOrder(BillingFlow $flow = BillingFlow::PayFirst, array $attributes = []): Order
{
    $order = Order::factory()->create([
        'billing_flow' => $flow,
        'buyer_type' => User::class,
        'buyer_id' => 7,
        'billing_vat' => '509999999',
        'is_business' => true,
        ...$attributes,
    ]);
    $order->addItem(['name' => 'Consulting', 'unit_net_price' => '10', 'tax_rate' => 23]);
    $order->confirm();

    return $order->refresh();
}

function billingAttempt(Model $attempt): PaymentAttempt
{
    assert($attempt instanceof PaymentAttempt);

    return $attempt;
}

function billingInvoice(Order $order): InvoiceDocument
{
    return InvoiceDocument::query()->where('type', DocumentType::Invoice)
        ->where('invoiceable_type', 'order')->where('invoiceable_id', $order->id)->sole();
}

function billingReceipt(Payment $payment): InvoiceDocument
{
    return InvoiceDocument::query()->where('type', DocumentType::Receipt)
        ->where('invoiceable_type', 'payment')->where('invoiceable_id', $payment->id)->sole();
}

/**
 * Receipt link amounts, keyed by the invoice they settle.
 *
 * @return array<int, int>
 */
function billingLinks(InvoiceDocument $receipt): array
{
    return InvoiceDocumentLink::query()->where('document_id', $receipt->id)->orderBy('associated_document_id')
        ->pluck('amount_cents', 'associated_document_id')->all();
}

function billingReceiptCount(): int
{
    return InvoiceDocument::query()->where('type', DocumentType::Receipt)->count();
}

/**
 * The invoice-receipt was issued for the order's total, with the payment's method and date.
 */
function expectInvoiceReceiptFor(Order $order, Payment $payment): void
{
    $document = InvoiceDocument::query()->sole();
    expect($document->type)->toBe(DocumentType::InvoiceReceipt)
        ->and($document->status)->toBe(DocumentStatus::Issued)
        ->and($document->invoiceable_type)->toBe('order')
        ->and($document->invoiceable_id)->toBe($order->id)
        ->and($document->expected_total_cents)->toBe(1230)
        ->and($document->payload['payment'] ?? null)->toBe((new PaymentData($payment->method->value, $payment->paid_at, 1230))->toArray());
}

it('issues one invoice-receipt for a circuit A order paid through EuPago', function () {
    Moloni::fake();
    $eupago = Eupago::fake();
    $order = billingOrder();

    $eupago->pay(billingAttempt(Commerce::multibanco($order)));

    expect($order->refresh()->status)->toBe(OrderStatus::Paid);
    expectInvoiceReceiptFor($order, Payment::query()->sole());
});

it('issues one invoice-receipt for a circuit A order paid by hand, dated as the payment', function () {
    Moloni::fake();
    $order = billingOrder();

    $payment = Commerce::recordPayment([[$order, 1230]], PaymentMethod::Cash, Carbon::parse('2026-11-10 15:00'));

    expectInvoiceReceiptFor($order, $payment);
});

it('keeps the order paid when its invoice-receipt fails or cannot be requested', function (Closure $breakMoloni, Closure $expect) {
    Exceptions::fake();
    $eupago = Eupago::fake();
    $order = billingOrder();
    $attempt = billingAttempt(Commerce::multibanco($order));
    Event::fake([PayableSettled::class]);
    $breakMoloni();

    $eupago->pay($attempt);

    expect($order->refresh()->status)->toBe(OrderStatus::Paid)
        ->and(Payment::query()->sole()->allocations()->sole()->amount_cents)->toBe(1230)
        ->and($attempt->refresh()->status)->toBe(AttemptStatus::Paid)
        ->and($attempt->webhookEvents()->latest('id')->firstOrFail()->status)->toBe(EventStatus::Processed);
    Event::assertDispatchedTimes(PayableSettled::class, 1);
    $expect();
})->with([
    'Moloni refuses the document' => [
        fn () => Moloni::fake()->failNext(FailureType::Validation),
        function () {
            expect(InvoiceDocument::query()->sole()->status)->toBe(DocumentStatus::Failed);
            Exceptions::assertNothingReported();
        },
    ],
    'the request throws' => [
        fn () => Moloni::shouldReceive('invoiceReceipt')->andThrow(new RuntimeException('Moloni is down')),
        function () {
            expect(InvoiceDocument::query()->count())->toBe(0);
            Exceptions::assertReported(fn (RuntimeException $e) => $e->getMessage() === 'Moloni is down');
        },
    ],
]);

it('invoices a circuit B order at confirmation and issues one receipt per payment', function () {
    Moloni::fake();
    $eupago = Eupago::fake();
    $order = billingOrder(BillingFlow::InvoiceFirst, ['payment_terms_days' => 30]);

    $invoice = billingInvoice($order);
    expect($invoice->status)->toBe(DocumentStatus::Issued)
        ->and($invoice->due_at?->toDateString())->toBe(MoloniManager::today()->addDays(30)->toDateString());

    $eupago->pay(billingAttempt(Commerce::multibanco($order, 1000)));
    $second = Commerce::recordPayment([[$order, 230]], PaymentMethod::Transfer, now());

    $first = Payment::query()->whereKeyNot($second->id)->sole();
    expect(billingReceipt($first)->status)->toBe(DocumentStatus::Issued)
        ->and(billingLinks(billingReceipt($first)))->toBe([$invoice->id => 1000])
        ->and(billingLinks(billingReceipt($second)))->toBe([$invoice->id => 230])
        ->and($order->refresh()->status)->toBe(OrderStatus::Paid)
        ->and($invoice->outstandingCents())->toBe(0);
});

it('issues the receipt of a payment recorded before the invoice once the invoice is issued', function () {
    $moloni = Moloni::fake()->failNext(FailureType::Transient);
    $order = billingOrder(BillingFlow::InvoiceFirst);
    $invoice = billingInvoice($order);

    $payment = Commerce::recordPayment([[$order, 500]], PaymentMethod::Transfer, now());

    expect($invoice->status)->toBe(DocumentStatus::Failed)
        ->and(billingReceiptCount())->toBe(0);

    $moloni->retry($invoice);

    expect(billingLinks(billingReceipt($payment)))->toBe([$invoice->id => 500]);
});

it('issues one joint receipt for a payment across orders of one customer', function () {
    Moloni::fake();
    $first = billingOrder(BillingFlow::InvoiceFirst);
    $second = billingOrder(BillingFlow::InvoiceFirst);
    $third = billingOrder(BillingFlow::InvoiceFirst);

    $payment = Commerce::recordPayment([[$first, 1230], [$second, 500], [$third, 1230]], PaymentMethod::Transfer, now());

    $receipt = billingReceipt($payment);
    expect(billingReceiptCount())->toBe(1)
        ->and($receipt->expected_total_cents)->toBe(2960)
        ->and(billingLinks($receipt))->toBe([
            billingInvoice($first)->id => 1230,
            billingInvoice($second)->id => 500,
            billingInvoice($third)->id => 1230,
        ])
        ->and($first->refresh()->status)->toBe(OrderStatus::Paid)
        ->and($second->refresh()->status)->toBe(OrderStatus::PartiallyPaid)
        ->and($third->refresh()->status)->toBe(OrderStatus::Paid);
});

it('refuses a joint payment one receipt cannot settle, and writes nothing', function (Closure $second) {
    Moloni::fake();
    $first = billingOrder(BillingFlow::InvoiceFirst);
    $other = $second();

    expect(fn () => Commerce::recordPayment([[$first, 1230], [$other, 1230]], PaymentMethod::Transfer, now()))->toThrow(CommerceException::class)
        ->and(Payment::query()->count())->toBe(0)
        ->and(PaymentAllocation::query()->count())->toBe(0)
        ->and(billingReceiptCount())->toBe(0);
})->with([
    'another customer' => [fn () => billingOrder(BillingFlow::InvoiceFirst, ['buyer_id' => 8])],
    'a final consumer' => [fn () => billingOrder(BillingFlow::InvoiceFirst, ['billing_vat' => null, 'is_business' => false])],
    'an invoice not issued' => [function () {
        Moloni::fake()->failNext(FailureType::Validation);

        return billingOrder(BillingFlow::InvoiceFirst);
    }],
]);

it('settles the invoice of a payment under review as soon as it is allocated', function () {
    Moloni::fake();
    $order = billingOrder(BillingFlow::InvoiceFirst);
    $payment = Payment::factory()->for($order, 'payable')->create([
        'source' => PaymentSource::Eupago,
        'external_id' => 'trid-1',
        'amount_cents' => 2000,
        'needs_review' => true,
        'review_reason' => 'exceeds_due',
    ]);

    Commerce::allocate($payment, $order);

    expect($payment->refresh()->needs_review)->toBeTrue()
        ->and(billingLinks(billingReceipt($payment)))->toBe([billingInvoice($order)->id => 1230]);
});

it('requests no second document when synced again', function (BillingFlow $flow, int $documents) {
    $moloni = Moloni::fake();
    $order = billingOrder($flow);
    Commerce::recordPayment([[$order, 1230]], PaymentMethod::Transfer, now());

    app(Billing::class)->sync($order);
    app(Billing::class)->sync($order);

    expect(InvoiceDocument::query()->count())->toBe($documents);
    $moloni->assertIssuedCount($documents);
})->with([
    'circuit A: the invoice-receipt' => [BillingFlow::PayFirst, 1],
    'circuit B: the invoice and the receipt' => [BillingFlow::InvoiceFirst, 2],
]);

it('requests on recover what a lost after-commit sync missed', function () {
    Moloni::fake();
    app()->instance(Billing::class, Mockery::spy(Billing::class));
    $paidFirst = billingOrder();
    Commerce::recordPayment([[$paidFirst, 1230]], PaymentMethod::Transfer, now());
    $invoicedFirst = billingOrder(BillingFlow::InvoiceFirst);
    $payment = Commerce::recordPayment([[$invoicedFirst, 500]], PaymentMethod::Transfer, now());
    app()->forgetInstance(Billing::class);

    expect(InvoiceDocument::query()->count())->toBe(0);

    expect(Artisan::call('commerce:recover'))->toBe(0);

    expect(InvoiceDocument::query()->where('invoiceable_id', $paidFirst->id)->sole()->type)->toBe(DocumentType::InvoiceReceipt)
        ->and(billingInvoice($invoicedFirst)->status)->toBe(DocumentStatus::Issued)
        ->and(billingReceipt($payment)->status)->toBe(DocumentStatus::Issued);
});

it('does not request a failed document again on recover', function () {
    Moloni::fake()->failNext(FailureType::Validation);
    $order = billingOrder(BillingFlow::InvoiceFirst);

    expect(Artisan::call('commerce:recover'))->toBe(0);

    expect(billingInvoice($order)->status)->toBe(DocumentStatus::Failed);
});

it('refuses to void a payment while its receipt is live', function () {
    Moloni::fake();
    $order = billingOrder(BillingFlow::InvoiceFirst);
    $payment = Commerce::recordPayment([[$order, 500]], PaymentMethod::Transfer, now());

    expect(fn () => Commerce::voidPayment($payment, 'Bounced'))->toThrow(CommerceException::class)
        ->and($payment->refresh()->voided_at)->toBeNull();
});

it('voids a payment without a receipt and never requests one for it', function () {
    $moloni = Moloni::fake()->failNext(FailureType::Validation);
    $order = billingOrder(BillingFlow::InvoiceFirst);
    $payment = Commerce::recordPayment([[$order, 500]], PaymentMethod::Transfer, now());

    Commerce::voidPayment($payment, 'Bounced');
    $moloni->retry(billingInvoice($order));

    expect($payment->refresh()->voided_at)->not->toBeNull()
        ->and(billingInvoice($order)->status)->toBe(DocumentStatus::Issued)
        ->and(billingReceiptCount())->toBe(0);
});

it('confirms a circuit B order even when its invoice cannot be requested', function () {
    Exceptions::fake();

    // No Moloni::fake(): nothing is configured, so the request throws.
    $order = billingOrder(BillingFlow::InvoiceFirst);

    expect($order->status)->toBe(OrderStatus::PendingPayment)
        ->and(InvoiceDocument::query()->count())->toBe(0);
    Exceptions::assertReported(MoloniException::class);
});
