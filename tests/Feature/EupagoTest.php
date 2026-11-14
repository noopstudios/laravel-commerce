<?php

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Noop\Commerce\Enums\BillingFlow;
use Noop\Commerce\Enums\OrderStatus;
use Noop\Commerce\Enums\PaymentMethod;
use Noop\Commerce\Enums\PaymentSource;
use Noop\Commerce\Events\PayableExpired;
use Noop\Commerce\Events\PayableSettled;
use Noop\Commerce\Events\PaymentNeedsReview;
use Noop\Commerce\Exceptions\CommerceException;
use Noop\Commerce\Facades\Commerce;
use Noop\Commerce\Models\Order;
use Noop\Commerce\Models\Payment;
use Noop\Eupago\Enums\AttemptStatus;
use Noop\Eupago\Enums\EventStatus;
use Noop\Eupago\Events\PaymentNeedsReview as EupagoPaymentNeedsReview;
use Noop\Eupago\Exceptions\EupagoException;
use Noop\Eupago\Facades\Eupago;
use Noop\Eupago\Models\PaymentAttempt;
use Symfony\Component\Finder\SplFileInfo;

const EUPAGO_PHONE = '912345678';

/**
 * A confirmed order of 12.30 (10.00 + 23% VAT), pending payment.
 */
function eupagoOrder(BillingFlow $flow = BillingFlow::PayFirst): Order
{
    $order = Order::factory()->create(['billing_flow' => $flow]);
    $order->addItem(['name' => 'Consulting', 'unit_net_price' => '10', 'tax_rate' => 23]);
    $order->confirm();

    return $order;
}

function eupagoAttempt(Model $attempt): PaymentAttempt
{
    expect($attempt)->toBeInstanceOf(PaymentAttempt::class);
    assert($attempt instanceof PaymentAttempt);

    return $attempt;
}

/**
 * The attempt was marked paid and its webhook event processed, not left to be retried.
 */
function expectAttemptSettled(PaymentAttempt $attempt): void
{
    expect($attempt->refresh()->status)->toBe(AttemptStatus::Paid)
        ->and($attempt->webhookEvents()->latest('id')->firstOrFail()->status)->toBe(EventStatus::Processed);
}

it('pays a circuit A order with one fully allocated EuPago payment', function () {
    $fake = Eupago::fake();
    Event::fake([PayableSettled::class]);
    $order = eupagoOrder();
    $attempt = eupagoAttempt(Commerce::multibanco($order));

    $fake->pay($attempt);

    $payment = Payment::query()->sole();
    expect($order->refresh()->status)->toBe(OrderStatus::Paid)
        ->and($order->paid_at)->not->toBeNull()
        ->and($payment->source)->toBe(PaymentSource::Eupago)
        ->and($payment->method)->toBe(PaymentMethod::Multibanco)
        ->and($payment->external_id)->toBe($attempt->refresh()->trid)
        ->and($payment->needs_review)->toBeFalse()
        ->and($payment->allocations()->sole()->amount_cents)->toBe(1230);
    Event::assertDispatchedTimes(PayableSettled::class, 1);
});

it('pays a circuit B order in parts', function () {
    $fake = Eupago::fake();
    $order = eupagoOrder(BillingFlow::InvoiceFirst);

    $fake->pay(eupagoAttempt(Commerce::multibanco($order, 1000)));

    expect($order->refresh()->status)->toBe(OrderStatus::PartiallyPaid);

    $fake->pay(eupagoAttempt(Commerce::mbway($order, EUPAGO_PHONE)));

    expect($order->refresh()->status)->toBe(OrderStatus::Paid)
        ->and(Commerce::dueCents($order))->toBe(0)
        ->and(Payment::query()->where('method', PaymentMethod::Mbway)->sole()->amount_cents)->toBe(230);
});

it('sends a payment it cannot apply to review, leaving the order untouched', function (Closure $arrange, OrderStatus $status, int $due, string $reason) {
    $fake = Eupago::fake();
    $order = eupagoOrder($status === OrderStatus::PartiallyPaid ? BillingFlow::InvoiceFirst : BillingFlow::PayFirst);
    $attempt = eupagoAttempt(Commerce::multibanco($order));
    $arrange($fake, $order, $attempt);
    Event::fake([PaymentNeedsReview::class, PayableSettled::class, EupagoPaymentNeedsReview::class]);

    $fake->pay($attempt);

    $payment = Payment::query()->where('source', PaymentSource::Eupago)->where('external_id', $attempt->refresh()->trid)->sole();
    expect($payment->allocations()->count())->toBe(0)
        ->and($payment->needs_review)->toBeTrue()
        ->and($payment->review_reason)->toBe($reason)
        ->and($order->refresh()->status)->toBe($status)
        ->and(Commerce::dueCents($order))->toBe($due);
    expectAttemptSettled($attempt);
    Event::assertDispatched(PaymentNeedsReview::class, fn (PaymentNeedsReview $event) => $event->payment->is($payment));
    Event::assertNotDispatched(PayableSettled::class);
    Event::assertNotDispatched(EupagoPaymentNeedsReview::class);
})->with([
    'late, after the order was cancelled' => [fn ($fake, Order $order) => $order->cancel(), OrderStatus::Cancelled, 1230, 'not_accepting_payments'],
    'late, after its only attempt expired' => [fn ($fake, Order $order, PaymentAttempt $attempt) => $fake->expire($attempt), OrderStatus::Expired, 1230, 'not_accepting_payments'],
    'duplicate, a second reference paid' => [fn ($fake, Order $order) => $fake->pay(eupagoAttempt(Commerce::mbway($order, EUPAGO_PHONE))), OrderStatus::Paid, 0, 'not_accepting_payments'],
    'excess, above a due a manual payment reduced' => [
        fn ($fake, Order $order) => Commerce::recordPayment([[$order, 500]], PaymentMethod::Transfer, now()),
        OrderStatus::PartiallyPaid, 730, 'exceeds_due',
    ],
]);

it('expires a circuit A order only when no other attempt is open', function () {
    $fake = Eupago::fake();
    Event::fake([PayableExpired::class]);
    $order = eupagoOrder();
    $first = eupagoAttempt(Commerce::multibanco($order));
    $second = eupagoAttempt(Commerce::mbway($order, EUPAGO_PHONE));

    $fake->expire($first);

    expect($order->refresh()->status)->toBe(OrderStatus::PendingPayment);
    Event::assertNotDispatched(PayableExpired::class);

    $fake->expire($second);

    expect($order->refresh()->status)->toBe(OrderStatus::Expired);
    Event::assertDispatchedTimes(PayableExpired::class, 1);
});

it('never expires a circuit B order', function () {
    $fake = Eupago::fake();
    $order = eupagoOrder(BillingFlow::InvoiceFirst);

    $fake->expire(eupagoAttempt(Commerce::multibanco($order)));

    expect($order->refresh()->status)->toBe(OrderStatus::PendingPayment);
});

it('refuses a request for an order that cannot receive payment', function () {
    Eupago::fake();
    $order = eupagoOrder();
    $order->cancel();

    expect(fn () => Commerce::multibanco($order))->toThrow(CommerceException::class)
        ->and(PaymentAttempt::query()->count())->toBe(0);
});

it('refuses a circuit A request for other than the due', function (int $cents) {
    Eupago::fake();
    $order = eupagoOrder();

    expect(fn () => Commerce::mbway($order, EUPAGO_PHONE, $cents))->toThrow(CommerceException::class)
        ->and(PaymentAttempt::query()->count())->toBe(0);
})->with([1229, 1231]);

it('requests the current due by default', function () {
    Eupago::fake();
    $order = eupagoOrder(BillingFlow::InvoiceFirst);
    Commerce::recordPayment([[$order, 500]], PaymentMethod::Transfer, now());

    $attempt = eupagoAttempt(Commerce::mbway($order, EUPAGO_PHONE));

    expect($attempt->amount_cents)->toBe(730)
        ->and($attempt->status)->toBe(AttemptStatus::Pending);
});

it('lets the EuPago exception through', function () {
    Eupago::fake();
    $order = eupagoOrder();

    expect(fn () => Commerce::mbway($order, '123'))->toThrow(EupagoException::class);
});

it('names laravel-eupago only under src/Eupago', function () {
    $offenders = collect(File::allFiles(__DIR__.'/../../src'))
        ->reject(fn (SplFileInfo $file) => str_starts_with($file->getRelativePath(), 'Eupago'))
        ->filter(fn (SplFileInfo $file) => str_contains((string) file_get_contents($file->getPathname()), 'Noop\\Eupago'))
        ->map(fn (SplFileInfo $file) => $file->getRelativePathname());

    expect($offenders->all())->toBe([]);
});
