<?php

use Carbon\CarbonInterface;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Noop\Commerce\Enums\BillingFlow;
use Noop\Commerce\Enums\OrderStatus;
use Noop\Commerce\Enums\PaymentMethod;
use Noop\Commerce\Enums\PaymentSource;
use Noop\Commerce\Facades\Commerce;
use Noop\Commerce\Filament\Resources\Orders\OrderDocuments;
use Noop\Commerce\Filament\Resources\Orders\OrderResource;
use Noop\Commerce\Filament\Resources\Orders\Pages\ViewOrder;
use Noop\Commerce\Filament\Resources\Payments\Pages\ListPayments;
use Noop\Commerce\Filament\Resources\Payments\Pages\ViewPayment;
use Noop\Commerce\Filament\Resources\Payments\PaymentResource;
use Noop\Commerce\Models\Order;
use Noop\Commerce\Models\Payment;
use Noop\Commerce\Tests\Fixtures\User;
use Noop\Eupago\Enums\AttemptStatus;
use Noop\Eupago\Enums\FailureType as EupagoFailure;
use Noop\Eupago\EupagoClient;
use Noop\Eupago\Exceptions\EupagoException;
use Noop\Eupago\Facades\Eupago;
use Noop\Eupago\Models\PaymentAttempt;
use Noop\Eupago\Testing\FakeEupagoClient;
use Noop\Moloni\Enums\DocumentStatus;
use Noop\Moloni\Enums\DocumentType;
use Noop\Moloni\Enums\FailureType;
use Noop\Moloni\Facades\Moloni;
use Noop\Moloni\Models\InvoiceDocument;
use Noop\Moloni\Models\InvoiceDocumentLink;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

beforeEach(function () {
    Filament::setCurrentPanel('admin');
    actingAs(User::create(['name' => 'Ana', 'email' => 'ana@example.test', 'password' => 'secret']));
});

/**
 * Grants the abilities named in commerce.permissions under these keys.
 */
function grantCommerce(string ...$keys): void
{
    foreach ($keys as $key) {
        Gate::define((string) config("commerce.permissions.{$key}"), fn () => true);
    }
}

/**
 * A confirmed order of 12.30 (10.00 + 23% VAT) of a business customer, pending payment.
 */
function panelOrder(BillingFlow $flow = BillingFlow::PayFirst): Order
{
    $order = Order::factory()->create([
        'billing_flow' => $flow,
        'buyer_type' => User::class,
        'buyer_id' => 7,
        'billing_vat' => '509999999',
        'is_business' => true,
    ]);
    $order->addItem(['name' => 'Consulting', 'unit_net_price' => '10', 'tax_rate' => 23]);
    $order->confirm();

    return $order->refresh();
}

/**
 * An EuPago payment under review that arrived for the order.
 */
function reviewPayment(Order $order): Payment
{
    return Payment::factory()->for($order, 'payable')->create([
        'source' => PaymentSource::Eupago,
        'method' => PaymentMethod::Multibanco,
        'external_id' => 'trid-1',
        'amount_cents' => 1230,
        'needs_review' => true,
        'review_reason' => 'not_accepting_payments',
    ]);
}

function documentsOf(Order|Payment $invoiceable, DocumentType $type): InvoiceDocument
{
    return InvoiceDocument::query()->where('type', $type)
        ->where('invoiceable_type', $invoiceable->getMorphClass())->where('invoiceable_id', $invoiceable->getKey())
        ->latest('id')->firstOrFail();
}

function viewOrder(Order $order): mixed
{
    return Livewire::test(ViewOrder::class, ['record' => $order->getKey()]);
}

it('forbids the pages without view', function () {
    Moloni::fake();
    $eupago = Eupago::fake();
    // A circuit B order with an invoice, a payment and its receipt, an attempt and a payment under review.
    $order = panelOrder(BillingFlow::InvoiceFirst);
    $manual = Commerce::recordPayment([[$order, 500]], PaymentMethod::Transfer, now());
    $attempt = Commerce::multibanco($order, 100);
    assert($attempt instanceof PaymentAttempt);
    $eupago->expire($attempt);
    $review = reviewPayment($order);
    $pages = [
        OrderResource::getUrl('index'),
        OrderResource::getUrl('view', ['record' => $order]),
        PaymentResource::getUrl('index'),
        PaymentResource::getUrl('view', ['record' => $review]),
    ];

    foreach ($pages as $page) {
        get($page)->assertForbidden();
    }

    grantCommerce('view');

    foreach ($pages as $page) {
        get($page)->assertOk();
    }

    // An unset permission grants nothing, whatever the Gate says about the default name.
    config(['commerce.permissions.view' => null]);
    get(OrderResource::getUrl('index'))->assertForbidden();
});

it('refuses every action to a user who may only view, and runs it once manage is granted', function () {
    grantCommerce('view');
    $moloni = Moloni::fake();
    Eupago::fake();
    $toPay = panelOrder();
    $toCancel = panelOrder();
    $review = reviewPayment(panelOrder());
    $toVoid = panelOrder();
    // Its invoice-receipt fails, so no live document stops the void.
    $moloni->failNext(FailureType::Validation);
    $voidable = Commerce::recordPayment([[$toVoid, 1230]], PaymentMethod::Cash, now());

    $hidden = fn () => [
        viewOrder($toPay)->assertActionHidden('recordPayment')->assertActionHidden('multibanco')->assertActionHidden('mbway')->assertActionHidden('proforma'),
        viewOrder($toCancel)->assertActionHidden('cancelOrder'),
        viewOrder($toVoid)->assertActionHidden('issueDocuments'),
        Livewire::test(ListPayments::class)
            ->assertActionHidden(TestAction::make('applyToOrder')->table($review))
            ->assertActionHidden(TestAction::make('resolveReview')->table($review)),
        Livewire::test(ViewPayment::class, ['record' => $voidable->getKey()])->assertActionHidden('voidPayment'),
    ];
    // Mounted and called directly, past the visibility check of callAction().
    $run = fn () => [
        viewOrder($toPay)->mountAction('recordPayment')->fillForm(['method' => PaymentMethod::Cash->value])->callMountedAction(),
        viewOrder($toCancel)->mountAction('cancelOrder')->callMountedAction(),
        Livewire::test(ListPayments::class)->mountAction(TestAction::make('applyToOrder')->table($review))->callMountedAction(),
        Livewire::test(ViewPayment::class, ['record' => $voidable->getKey()])->mountAction('voidPayment')->fillForm(['reason' => 'Recorded twice.'])->callMountedAction(),
    ];

    $hidden();
    $run();

    expect($toPay->refresh()->status)->toBe(OrderStatus::PendingPayment)
        ->and($toCancel->refresh()->status)->toBe(OrderStatus::PendingPayment)
        ->and($review->refresh()->needs_review)->toBeTrue()
        ->and($voidable->refresh()->voided_at)->toBeNull();

    // The same calls go through once manage is granted.
    grantCommerce('manage');
    $run();

    expect($toPay->refresh()->status)->toBe(OrderStatus::Paid)
        ->and($toCancel->refresh()->status)->toBe(OrderStatus::Cancelled)
        ->and($review->refresh()->needs_review)->toBeFalse()
        ->and($voidable->refresh()->voided_at)->not->toBeNull();
});

it('records a circuit A payment by hand and issues its invoice-receipt', function () {
    grantCommerce('view', 'manage');
    Moloni::fake();
    $order = panelOrder();

    viewOrder($order)
        ->callAction('recordPayment', ['method' => PaymentMethod::Cash->value])
        ->assertHasNoFormErrors()
        ->assertNotified(__('The payment was recorded.'));

    $payment = Payment::query()->sole();
    expect($order->refresh()->status)->toBe(OrderStatus::Paid)
        ->and($payment->amount_cents)->toBe(1230)
        ->and($payment->method)->toBe(PaymentMethod::Cash)
        ->and($payment->recorded_by_id)->toBe(auth()->id())
        ->and(documentsOf($order, DocumentType::InvoiceReceipt)->status)->toBe(DocumentStatus::Issued);
});

it('records one circuit B payment across two orders of one customer, with one receipt', function () {
    grantCommerce('view', 'manage');
    Moloni::fake();
    $first = panelOrder(BillingFlow::InvoiceFirst);
    $second = panelOrder(BillingFlow::InvoiceFirst);

    viewOrder($first)
        ->callAction('recordPayment', [
            'amount' => '12.30',
            'method' => PaymentMethod::Transfer->value,
            'others' => [['order' => $second->id, 'amount' => '5.00']],
        ])
        ->assertHasNoFormErrors();

    $payment = Payment::query()->sole();
    $receipt = documentsOf($payment, DocumentType::Receipt);
    expect(InvoiceDocument::query()->where('type', DocumentType::Receipt)->count())->toBe(1)
        ->and(InvoiceDocumentLink::query()->where('document_id', $receipt->id)->orderBy('associated_document_id')->pluck('amount_cents', 'associated_document_id')->all())
        ->toBe([documentsOf($first, DocumentType::Invoice)->id => 1230, documentsOf($second, DocumentType::Invoice)->id => 500])
        ->and($first->refresh()->status)->toBe(OrderStatus::Paid)
        ->and($second->refresh()->status)->toBe(OrderStatus::PartiallyPaid);
});

it('shows what the ledger refuses and records nothing', function () {
    grantCommerce('view', 'manage');
    Moloni::fake();
    $order = panelOrder(BillingFlow::InvoiceFirst);

    viewOrder($order)
        ->callAction('recordPayment', ['amount' => '20.00', 'method' => PaymentMethod::Transfer->value])
        ->assertNotified("The amount of 2000 cents for {$order->number} is more than the 1230 it owes.");

    expect(Payment::query()->count())->toBe(0)
        ->and($order->refresh()->status)->toBe(OrderStatus::PendingPayment);
});

it('records a payment once when the same form is submitted twice', function () {
    grantCommerce('view', 'manage');
    Moloni::fake();
    $order = panelOrder(BillingFlow::InvoiceFirst);
    $first = viewOrder($order)->mountAction('recordPayment')->fillForm(['amount' => '5.00']);
    $key = $first->get('mountedActions.0.data.idempotency_key');
    $second = viewOrder($order)->mountAction('recordPayment')->fillForm(['amount' => '5.00', 'idempotency_key' => $key]);

    $first->callMountedAction()->assertHasNoFormErrors();
    $second->callMountedAction()->assertHasNoFormErrors();

    expect($key)->toBeString()->toHaveLength(26)
        ->and(Payment::query()->count())->toBe(1)
        ->and(Commerce::dueCents($order))->toBe(730);
});

it('generates a Multibanco reference', function () {
    grantCommerce('view', 'manage');
    Eupago::fake();
    $order = panelOrder();

    viewOrder($order)->callAction('multibanco')->assertNotified(__('Multibanco reference generated'));

    $attempt = PaymentAttempt::query()->sole();
    expect($attempt->status)->toBe(AttemptStatus::Pending)
        ->and($attempt->amount_cents)->toBe(1230)
        ->and($attempt->payable_id)->toBe($order->id);
});

it('shows each EuPago failure with its own message', function (Closure $break, string $message) {
    grantCommerce('view', 'manage');
    Eupago::fake();
    $order = panelOrder();
    $break($order);

    viewOrder($order)->callAction('multibanco')->assertNotified($message);

    expect(PaymentAttempt::query()->where('status', AttemptStatus::Pending)->count())->toBe(0);
})->with([
    'rejected' => [
        fn () => app()->singleton(EupagoClient::class, fn () => new class extends FakeEupagoClient
        {
            public function multibanco(string $identifier, int $amountCents, CarbonInterface $until): array
            {
                throw new EupagoException('EuPago refused the Multibanco reference: invalid entity.', EupagoFailure::Rejected);
            }
        }),
        'EuPago refused the request: EuPago refused the Multibanco reference: invalid entity. You may try again.',
    ],
    'unknown' => [
        fn () => app()->singleton(EupagoClient::class, fn () => new class extends FakeEupagoClient
        {
            public function multibanco(string $identifier, int $amountCents, CarbonInterface $until): array
            {
                throw new RuntimeException('Connection reset.');
            }
        }),
        'EuPago did not confirm the request, so a payable reference may exist. Do not try again: the order stays blocked until the payment notice, the expiry, or an operator resolves the attempt in the EuPago panel.',
    ],
    'blocked' => [
        fn (Order $order) => PaymentAttempt::factory()->for($order, 'payable')->create(['status' => AttemptStatus::Unknown]),
        'The request was not sent: another request is in progress, an earlier one is unresolved, or the attempts limit was reached. An earlier payment request for this payable has an unknown outcome.',
    ],
]);

it('applies a late EuPago payment to its expired order, reopening and paying it', function () {
    grantCommerce('view', 'manage');
    Moloni::fake();
    $eupago = Eupago::fake();
    $order = panelOrder();
    $attempt = Commerce::multibanco($order);
    assert($attempt instanceof PaymentAttempt);
    $eupago->expire($attempt);
    $eupago->pay($attempt);
    $late = Payment::query()->sole();
    $manual = Commerce::recordPayment([[panelOrder(), 1230]], PaymentMethod::Cash, now());
    expect($order->refresh()->status)->toBe(OrderStatus::Expired)
        ->and($late->needs_review)->toBeTrue();

    // The list opens on the review queue; the order defaults to the one the payment arrived for.
    Livewire::test(ListPayments::class)
        ->assertCanSeeTableRecords([$late])
        ->assertCanNotSeeTableRecords([$manual])
        ->callAction(TestAction::make('applyToOrder')->table($late))
        ->assertHasNoFormErrors();

    expect($order->refresh()->status)->toBe(OrderStatus::Paid)
        ->and($late->refresh()->needs_review)->toBeFalse()
        ->and($late->allocations()->sole()->amount_cents)->toBe(1230)
        ->and(documentsOf($order, DocumentType::InvoiceReceipt)->status)->toBe(DocumentStatus::Issued);
});

it('takes a payment out of review without applying it', function () {
    grantCommerce('view', 'manage');
    $order = panelOrder();
    $order->cancel();
    $payment = reviewPayment($order);

    Livewire::test(ListPayments::class)
        ->callAction(TestAction::make('resolveReview')->table($payment), ['note' => 'Refunded by bank transfer.'])
        ->assertHasNoFormErrors();

    expect($payment->refresh()->needs_review)->toBeFalse()
        ->and($payment->notes)->toBe('Refunded by bank transfer.')
        ->and($payment->allocations()->count())->toBe(0)
        ->and($order->refresh()->status)->toBe(OrderStatus::Cancelled);
});

it('voids a manual payment without a live document, reopening the due', function () {
    grantCommerce('view', 'manage');
    Moloni::fake()->failNext(FailureType::Validation);
    $order = panelOrder();
    $payment = Commerce::recordPayment([[$order, 1230]], PaymentMethod::Cash, now());

    Livewire::test(ViewPayment::class, ['record' => $payment->getKey()])
        ->callAction('voidPayment', ['reason' => 'Recorded twice.'])
        ->assertHasNoFormErrors();

    expect($payment->refresh()->voided_at)->not->toBeNull()
        ->and($payment->void_reason)->toBe('Recorded twice.')
        ->and($payment->voided_by_id)->toBe(auth()->id())
        ->and($order->refresh()->status)->toBe(OrderStatus::PendingPayment)
        ->and(Commerce::dueCents($order))->toBe(1230);
});

it('does not void an EuPago payment or one with a live receipt', function () {
    grantCommerce('view', 'manage');
    Moloni::fake();
    $order = panelOrder(BillingFlow::InvoiceFirst);
    $receipted = Commerce::recordPayment([[$order, 500]], PaymentMethod::Transfer, now());
    $eupago = reviewPayment($order);
    expect(documentsOf($receipted, DocumentType::Receipt)->status)->toBe(DocumentStatus::Issued);

    foreach ([$receipted, $eupago] as $payment) {
        Livewire::test(ViewPayment::class, ['record' => $payment->getKey()])->assertActionHidden('voidPayment');
        Livewire::test(ViewPayment::class, ['record' => $payment->getKey()])->mountAction('voidPayment')->fillForm(['reason' => 'x'])->callMountedAction();

        expect($payment->refresh()->voided_at)->toBeNull();
    }
});

it('requests a failed invoice-receipt again', function () {
    grantCommerce('view', 'manage');
    Moloni::fake()->failNext(FailureType::Validation);
    $order = panelOrder();
    Commerce::recordPayment([[$order, 1230]], PaymentMethod::Cash, now());
    expect(documentsOf($order, DocumentType::InvoiceReceipt)->status)->toBe(DocumentStatus::Failed)
        ->and(OrderResource::documentMissing($order->refresh()))->toBeTrue();

    viewOrder($order)->callAction('issueDocuments')
        ->assertNotified(__('The missing documents were requested.'))
        ->assertDispatched(OrderDocuments::CHANGED);

    expect(documentsOf($order, DocumentType::InvoiceReceipt)->status)->toBe(DocumentStatus::Issued)
        ->and(InvoiceDocument::query()->where('type', DocumentType::InvoiceReceipt)->count())->toBe(2)
        ->and(OrderResource::documentMissing($order))->toBeFalse();
});

it('runs the Moloni document actions on the documents of an order, never retry', function () {
    grantCommerce('view');
    Gate::define('manage moloni', fn () => true);
    Moloni::fake();
    $order = panelOrder(BillingFlow::InvoiceFirst);
    $invoice = documentsOf($order, DocumentType::Invoice);

    Livewire::test(OrderDocuments::class, ['order' => $order])
        ->assertActionDoesNotExist(TestAction::make('retry')->table($invoice))
        ->callAction(TestAction::make('creditNote')->table($invoice));

    expect(documentsOf($order, DocumentType::CreditNote)->status)->toBe(DocumentStatus::Issued)
        ->and(Commerce::dueCents($order))->toBe(0);
});
