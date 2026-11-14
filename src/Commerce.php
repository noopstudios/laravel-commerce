<?php

namespace Noop\Commerce;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;
use Noop\Commerce\Contracts\Payable;
use Noop\Commerce\Enums\BillingFlow;
use Noop\Commerce\Enums\OrderStatus;
use Noop\Commerce\Enums\PaymentMethod;
use Noop\Commerce\Enums\PaymentSource;
use Noop\Commerce\Events\PayableSettled;
use Noop\Commerce\Exceptions\CommerceException;
use Noop\Commerce\Models\Order;
use Noop\Commerce\Models\Payment;
use Noop\Commerce\Models\PaymentAllocation;
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
     * @param  list<array{0: Model&Payable, 1: int}>  $allocations  Payable, cents.
     */
    public function recordPayment(
        array $allocations,
        PaymentMethod $method,
        CarbonInterface $paidAt,
        ?Model $recordedBy = null,
        ?string $notes = null,
        ?string $idempotencyKey = null,
    ): Payment {
        $idempotencyKey ??= (string) Str::ulid();

        if (($existing = Payment::query()->where('idempotency_key', $idempotencyKey)->first()) !== null) {
            return $existing;
        }

        if ($allocations === []) {
            throw new CommerceException('A payment needs at least one payable.');
        }

        $payables = array_column($allocations, 0);
        $first = $payables[0];

        try {
            $payment = $first->getConnection()->transaction(function () use ($allocations, $payables, $method, $paidAt, $recordedBy, $notes, $idempotencyKey) {
                $locked = $this->lockPayables($payables);

                // A call with the same key that committed while this one waited for the locks.
                if (($existing = Payment::query()->where('idempotency_key', $idempotencyKey)->lockForUpdate()->first()) !== null) {
                    return $existing;
                }

                foreach ($locked as $i => $payable) {
                    if (! $payable->canReceivePayment()) {
                        throw new CommerceException("{$payable->paymentLabel()} cannot receive a payment now.");
                    }

                    $this->assertPayable($payable, $allocations[$i][1], $this->due($payable, true), count($locked));
                }

                if (count($locked) > 1) {
                    $this->assertJointReceipt($locked);
                }

                $payment = new Payment([
                    'source' => PaymentSource::Manual,
                    'method' => $method,
                    'idempotency_key' => $idempotencyKey,
                    'currency' => $locked[0]->currency(),
                    'amount_cents' => array_sum(array_column($allocations, 1)),
                    'paid_at' => $paidAt,
                    'recorded_by_id' => $recordedBy?->getKey(),
                    'notes' => $notes,
                ]);
                $payment->payable()->associate($locked[0]);
                $payment->save();

                foreach ($locked as $i => $payable) {
                    $this->allocateLocked($payment, $payable, $allocations[$i][1]);
                }

                return $payment;
            });
        } catch (UniqueConstraintViolationException $e) {
            // A call with the same key committed first; a locking read sees it under any snapshot.
            return Payment::query()->where('idempotency_key', $idempotencyKey)->lockForUpdate()->first() ?? throw $e;
        }

        $this->refresh($payables);

        // Task 5: Billing::sync(...) runs here, after commit, for each payable the payment was allocated to.

        return $payment;
    }

    /**
     * Applies a payment under review to a payable: the administrator's decision. Reopens an
     * expired or cancelled order. The amount defaults to what is left of the payment, up to the due.
     */
    public function allocate(Payment $payment, Model&Payable $payable, ?int $amountCents = null): PaymentAllocation
    {
        $allocation = $payable->getConnection()->transaction(function () use ($payment, $payable, $amountCents) {
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
        });

        $this->refresh([$payable]);
        $payment->refresh();

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

        $payment->getConnection()->transaction(function () use ($payment, $payables, $reason, $by) {
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
        });

        $this->refresh($payables);
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

        $credited = (new InvoiceDocument)->getConnection()->table('invoice_document_links as l')
            ->join('invoice_documents as c', 'c.id', '=', 'l.document_id')
            ->join('invoice_documents as i', 'i.id', '=', 'l.associated_document_id')
            ->where('c.type', DocumentType::CreditNote->value)
            ->whereIn('c.status', array_map(fn (DocumentStatus $status) => $status->value, DocumentStatus::live()))
            ->where('i.invoiceable_type', $payable->getMorphClass())
            ->where('i.invoiceable_id', $payable->getKey())
            ->when($forUpdate, fn ($query) => $query->lockForUpdate())
            ->pluck('l.amount_cents');

        return max(0, $payable->totalCents() - (int) $allocated->sum() - (int) $credited->sum());
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
            ->exists();

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
