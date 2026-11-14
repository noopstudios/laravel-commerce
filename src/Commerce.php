<?php

namespace Noop\Commerce;

use Carbon\CarbonInterface;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;
use Noop\Commerce\Contracts\Payable;
use Noop\Commerce\Enums\BillingFlow;
use Noop\Commerce\Enums\OrderStatus;
use Noop\Commerce\Enums\PaymentMethod;
use Noop\Commerce\Enums\PaymentSource;
use Noop\Commerce\Eupago\EupagoBridge;
use Noop\Commerce\Events\PayableSettled;
use Noop\Commerce\Exceptions\CommerceException;
use Noop\Commerce\Models\Order;
use Noop\Commerce\Models\Payment;
use Noop\Commerce\Models\PaymentAllocation;
use Noop\Moloni\Actions\RequestReceipt;
use Noop\Moloni\Enums\DocumentStatus;
use Noop\Moloni\Enums\DocumentType;
use Noop\Moloni\Models\InvoiceDocument;

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

    /**
     * The order model the host configured (commerce.models.order).
     *
     * @return class-string<Order>
     */
    public static function orderModel(): string
    {
        /** @var class-string<Order> */
        return config('commerce.models.order');
    }

    /**
     * What the payable still owes: its total minus the allocations of payments not voided and what
     * live credit notes take off its invoices, never below zero. A plain read, for display.
     */
    public function dueCents(Model&Payable $payable): int
    {
        return $this->due($payable, false);
    }

    /**
     * Records a manual payment, fully allocated to the given payables. The same idempotency key
     * returns the payment already recorded.
     *
     * @param  array<array{0: Model&Payable, 1: int}>  $allocations  Payable, cents.
     */
    public function recordPayment(
        array $allocations,
        PaymentMethod $method,
        CarbonInterface $paidAt,
        ?Model $recordedBy = null,
        ?string $notes = null,
        ?string $idempotencyKey = null,
    ): Payment {
        $allocations = array_values($allocations);
        $idempotencyKey ??= (string) Str::ulid();

        if ($allocations === []) {
            throw new CommerceException('A payment needs at least one payable.');
        }

        if (count($allocations) > RequestReceipt::MAX_INVOICES) {
            throw new CommerceException('A payment can cover at most '.RequestReceipt::MAX_INVOICES.' payables.');
        }

        $payables = array_column($allocations, 0);
        $requestedCents = array_sum(array_column($allocations, 1));

        if (($existing = Payment::query()->where('idempotency_key', $idempotencyKey)->first()) !== null) {
            return $this->replayed($existing, $requestedCents, $payables);
        }

        $connection = $payables[0]->getConnection();

        try {
            $payment = $connection->transaction(function () use ($allocations, $payables, $requestedCents, $method, $paidAt, $recordedBy, $notes, $idempotencyKey) {
                $locked = $this->lockPayables($payables);

                // Inserted before the checks, as Moloni does: a call with the same key that committed
                // while this one waited for the locks fails here on the unique key, and a locking
                // read of a missing key (a gap lock) is never taken.
                $payment = new Payment([
                    'source' => PaymentSource::Manual,
                    'method' => $method,
                    'idempotency_key' => $idempotencyKey,
                    'currency' => $locked[0]->currency(),
                    'amount_cents' => $requestedCents,
                    'paid_at' => $paidAt,
                    'recorded_by_id' => $recordedBy?->getKey(),
                    'notes' => $notes,
                ]);
                $payment->payable()->associate($locked[0]);
                $payment->save();

                foreach ($locked as $i => $payable) {
                    if (! $payable->canReceivePayment()) {
                        throw new CommerceException("{$payable->paymentLabel()} cannot receive a payment now.");
                    }

                    $this->assertPayable($payable, $allocations[$i][1], $this->due($payable, true), count($locked));
                }

                if (count($locked) > 1) {
                    $this->assertJointReceipt($locked);
                }

                foreach ($locked as $i => $payable) {
                    $this->allocateLocked($payment, $payable, $allocations[$i][1]);
                }

                return $payment;
            }, self::attempts($connection));
        } catch (UniqueConstraintViolationException $e) {
            // A call with the same key committed first; a locking read sees it under any snapshot.
            $existing = Payment::query()->where('idempotency_key', $idempotencyKey)->lockForUpdate()->first() ?? throw $e;

            return $this->replayed($existing, $requestedCents, $payables);
        }

        $this->refresh($payables);

        // One receipt per payment: the first sync requests it, the others find it.
        foreach ($payables as $payable) {
            Billing::syncAfterCommit($payable);
        }

        return $payment;
    }

    /**
     * Requests a Multibanco reference for the due (or part of it in circuit B). Call it outside any
     * database transaction; EuPago's exception passes through.
     *
     * @return Model The pending EuPago payment attempt.
     */
    public function multibanco(Model&Payable $payable, ?int $amountCents = null): Model
    {
        return app(EupagoBridge::class)->multibanco($payable, $amountCents);
    }

    /**
     * Sends an MB WAY request for the due (or part of it in circuit B). Call it outside any database
     * transaction; EuPago's exception passes through.
     *
     * @return Model The pending EuPago payment attempt.
     */
    public function mbway(Model&Payable $payable, #[\SensitiveParameter] string $phone, ?int $amountCents = null): Model
    {
        return app(EupagoBridge::class)->mbway($payable, $phone, $amountCents);
    }

    /**
     * Applies a payment under review to a payable: the administrator's decision. Reopens an
     * expired or cancelled order. The amount defaults to what is left of the payment, up to the due.
     */
    public function allocate(Payment $payment, Model&Payable $payable, ?int $amountCents = null): PaymentAllocation
    {
        $connection = $payable->getConnection();
        $allocation = $connection->transaction(function () use ($payment, $payable, $amountCents) {
            [$locked] = $this->lockPayables([$payable]);
            $current = Payment::query()->whereKey($payment->getKey())->lockForUpdate()->firstOrFail();

            if (! $current->needs_review || $current->voided_at !== null) {
                throw new CommerceException("Payment {$current->id} is not under review.");
            }

            if ($locked instanceof Order && $locked->status === OrderStatus::Draft) {
                throw new CommerceException("Order {$locked->number} is a draft and cannot be paid.");
            }

            $remaining = $current->amount_cents - (int) $current->allocations()->lockForUpdate()->get()->sum('amount_cents');
            $due = $this->due($locked, true);
            $amountCents ??= min($remaining, $due);

            if ($amountCents > $remaining) {
                throw new CommerceException("Payment {$current->id} has only {$remaining} cents left to allocate.");
            }

            $this->assertPayable($locked, $amountCents, $due, 1);

            $allocation = $this->allocateLocked($current, $locked, $amountCents);

            if ($amountCents === $remaining) {
                $current->forceFill(['needs_review' => false])->save();
            }

            return $allocation;
        }, self::attempts($connection));

        $this->refresh([$payable]);
        $payment->refresh();

        Billing::syncAfterCommit($payable);

        return $allocation;
    }

    /**
     * Takes a payment out of review without allocating it (refunded, handled elsewhere).
     */
    public function resolveReview(Payment $payment, string $note): void
    {
        $payment->getConnection()->transaction(function () use ($payment, $note) {
            $current = Payment::query()->whereKey($payment->getKey())->lockForUpdate()->firstOrFail();

            if (! $current->needs_review) {
                throw new CommerceException("Payment {$current->id} is not under review.");
            }

            $current->forceFill([
                'needs_review' => false,
                'notes' => $current->notes === null ? $note : $current->notes."\n".$note,
            ])->save();
        });

        $payment->refresh();

        // A circuit B receipt waits for the review to end.
        foreach ($payment->allocations()->with('payable')->get() as $allocation) {
            if ($allocation->payable instanceof Payable) {
                Billing::syncAfterCommit($allocation->payable);
            }
        }
    }

    /**
     * Voids a manual payment no live document depends on; the payables it paid owe it again.
     */
    public function voidPayment(Payment $payment, string $reason, ?Model $by = null): void
    {
        // Safe to read before the locks: only allocate() adds allocations, and never to a manual payment.
        /** @var list<Model&Payable> $payables */
        $payables = $payment->allocations()->with('payable')->get()
            ->map(fn (PaymentAllocation $allocation) => $allocation->payable)
            ->filter(fn (?Model $payable) => $payable instanceof Payable)
            ->values()->all();

        $connection = $payment->getConnection();
        $connection->transaction(function () use ($payment, $payables, $reason, $by) {
            $locked = $this->lockPayables($payables);
            $current = Payment::query()->whereKey($payment->getKey())->lockForUpdate()->firstOrFail();

            if ($current->source !== PaymentSource::Manual) {
                throw new CommerceException("Payment {$current->id} was not recorded by hand and cannot be voided.");
            }

            if ($current->voided_at !== null) {
                throw new CommerceException("Payment {$current->id} is already voided.");
            }

            $this->assertNoLiveDocument($current, $locked);

            $current->forceFill([
                'voided_at' => now(),
                'voided_by_id' => $by?->getKey(),
                'void_reason' => $reason,
            ])->save();

            foreach ($locked as $payable) {
                $payable->onDueChanged($this->due($payable, true));
            }
        }, self::attempts($connection));

        $payment->refresh();
    }

    /**
     * Locks the payables in (morph type, id) order and re-reads them. Call inside a transaction.
     *
     * @internal
     *
     * @param  list<Model&Payable>  $payables
     * @return list<Model&Payable> The locked instances, in the order given.
     */
    public function lockPayables(array $payables): array
    {
        $keys = array_map(fn (Model $payable) => [$payable->getMorphClass(), $payable->getKey()], $payables);

        if (count(array_unique(array_map('serialize', $keys))) !== count($keys)) {
            throw new CommerceException('A payable appears more than once in the payment.');
        }

        $order = array_keys($keys);
        usort($order, fn (int $a, int $b) => $keys[$a] <=> $keys[$b]);

        $locked = [];

        foreach ($order as $i) {
            $locked[$i] = $payables[$i]->newQuery()->whereKey($payables[$i]->getKey())->lockForUpdate()->firstOrFail();
        }

        ksort($locked);

        /** @var list<Model&Payable> */
        return array_values($locked);
    }

    /**
     * Allocates part of a payment to a locked payable whose due was checked under the lock, then
     * tells the payable its new due. Call inside the transaction that locked it.
     *
     * @internal
     */
    public function allocateLocked(Payment $payment, Model&Payable $payable, int $amountCents): PaymentAllocation
    {
        $allocation = new PaymentAllocation(['amount_cents' => $amountCents]);
        $allocation->payment()->associate($payment);
        $allocation->payable()->associate($payable);
        $allocation->save();

        $due = $this->due($payable, true);
        $payable->onDueChanged($due);

        if ($due === 0) {
            event(new PayableSettled($payable));
        }

        return $allocation;
    }

    /**
     * With $forUpdate, the rows are read with locking reads (and summed here: PostgreSQL refuses
     * FOR UPDATE on an aggregate), so a transaction whose snapshot predates the payable lock still
     * sees what others committed while it waited.
     *
     * @internal
     */
    public function due(Model&Payable $payable, bool $forUpdate): int
    {
        $allocated = $payable->getConnection()->table('payment_allocations as a')
            ->join('payments as p', 'p.id', '=', 'a.payment_id')
            ->where('a.payable_type', $payable->getMorphClass())
            ->where('a.payable_id', $payable->getKey())
            ->whereNull('p.voided_at')
            ->when($forUpdate, fn ($query) => $query->lockForUpdate())
            ->pluck('a.amount_cents');

        $live = array_map(fn (DocumentStatus $status) => $status->value, DocumentStatus::live());
        $credited = (new InvoiceDocument)->getConnection()->table('invoice_document_links as l')
            ->join('invoice_documents as c', 'c.id', '=', 'l.document_id')
            ->join('invoice_documents as i', 'i.id', '=', 'l.associated_document_id')
            ->where('c.type', DocumentType::CreditNote->value)
            ->whereIn('c.status', $live)
            ->whereIn('i.status', $live)
            ->where('i.invoiceable_type', $payable->getMorphClass())
            ->where('i.invoiceable_id', $payable->getKey())
            ->when($forUpdate, fn ($query) => $query->lockForUpdate())
            ->pluck('l.amount_cents');

        return max(0, $payable->totalCents() - (int) $allocated->sum() - (int) $credited->sum());
    }

    /**
     * A repeated idempotency key stands for the same request only: a payment recorded by hand, still
     * valid, for the same total.
     *
     * @param  list<Model&Payable>  $payables  The caller's instances.
     */
    private function replayed(Payment $existing, int $requestedCents, array $payables): Payment
    {
        if ($existing->voided_at !== null || $existing->source !== PaymentSource::Manual || $existing->amount_cents !== $requestedCents) {
            throw new CommerceException("The payment key {$existing->idempotency_key} already belongs to another payment; use a new key.");
        }

        $this->refresh($payables);

        return $existing;
    }

    /**
     * The amount rules shared by recordPayment() and allocate().
     */
    private function assertPayable(Model&Payable $payable, int $amountCents, int $dueCents, int $payables): void
    {
        $label = $payable->paymentLabel();

        if ($amountCents <= 0) {
            throw new CommerceException("The amount for {$label} must be greater than zero.");
        }

        if ($amountCents > $dueCents) {
            throw new CommerceException("The amount of {$amountCents} cents for {$label} is more than the {$dueCents} it owes.");
        }

        if ($payable->billingFlow() === BillingFlow::PayFirst && $payables > 1) {
            throw new CommerceException("{$label} is paid before it is invoiced and cannot share a payment.");
        }

        if ($payable->billingFlow() === BillingFlow::PayFirst && $amountCents !== $dueCents) {
            throw new CommerceException("{$label} must be paid in full: {$dueCents} cents.");
        }
    }

    /**
     * One receipt will settle every invoice: each issued, all of the same Moloni customer, never a final consumer.
     *
     * @param  list<Model&Payable>  $payables  Locked.
     */
    private function assertJointReceipt(array $payables): void
    {
        $customers = [];

        foreach ($payables as $payable) {
            $label = $payable->paymentLabel();
            $invoice = InvoiceDocument::query()->where('active_key', InvoiceDocument::activeKeyFor($payable))->first();

            if ($invoice === null || $invoice->type !== DocumentType::Invoice || $invoice->status !== DocumentStatus::Issued) {
                throw new CommerceException("{$label} has no issued invoice to settle.");
            }

            if ($invoice->customer_vat === null) {
                throw new CommerceException("{$label} was invoiced to a final consumer and cannot share a receipt.");
            }

            $customers[$invoice->customer_id] = true;
        }

        if (count($customers) !== 1) {
            throw new CommerceException('The payables of one payment must be invoiced to the same Moloni customer.');
        }
    }

    /**
     * Refuses a void while a live receipt of the payment, or a live invoice-receipt of a paid
     * circuit A payable, exists or may come to exist in Moloni.
     *
     * @param  list<Model&Payable>  $payables  Locked.
     */
    private function assertNoLiveDocument(Payment $payment, array $payables): void
    {
        $live = fn (DocumentType $type, Model $invoiceable) => InvoiceDocument::query()
            ->where('type', $type)
            ->whereIn('status', DocumentStatus::live())
            ->where('invoiceable_type', $invoiceable->getMorphClass())
            ->where('invoiceable_id', $invoiceable->getKey())
            // A locking read: under a host transaction's older snapshot a plain read could miss a
            // document Billing committed while this waited for the payment lock.
            ->lockForUpdate()
            ->first() !== null;

        if ($live(DocumentType::Receipt, $payment)) {
            throw new CommerceException("Payment {$payment->id} has a live receipt.");
        }

        foreach ($payables as $payable) {
            if ($payable->billingFlow() === BillingFlow::PayFirst && $live(DocumentType::InvoiceReceipt, $payable)) {
                throw new CommerceException("{$payable->paymentLabel()} has a live invoice-receipt.");
            }
        }
    }

    /**
     * The locking reads can deadlock on InnoDB gap locks, even between unrelated payables: retried
     * when the package owns the outermost transaction; inside a host transaction the deadlock
     * propagates, since the database already rolled that transaction back.
     *
     * @return positive-int
     */
    private static function attempts(ConnectionInterface $connection): int
    {
        return $connection->transactionLevel() === 0 ? 3 : 1;
    }

    /**
     * Brings the caller's instances up to date after the transaction committed.
     *
     * @param  array<Model>  $payables
     */
    private function refresh(array $payables): void
    {
        foreach ($payables as $payable) {
            $payable->refresh();
        }
    }
}
