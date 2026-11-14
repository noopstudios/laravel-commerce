<?php

namespace Noop\Commerce\Eupago;

use Illuminate\Database\Eloquent\Model;
use Noop\Commerce\Commerce;
use Noop\Commerce\Contracts\Payable;
use Noop\Commerce\Enums\BillingFlow;
use Noop\Commerce\Enums\PaymentMethod;
use Noop\Commerce\Enums\PaymentSource;
use Noop\Commerce\Events\PaymentNeedsReview;
use Noop\Commerce\Models\Payment;
use Noop\Eupago\Contracts\HandlesEupagoPayments;
use Noop\Eupago\Data\ConfirmedPayment;
use Noop\Eupago\Enums\AttemptStatus;
use Noop\Eupago\Models\PaymentAttempt;

/**
 * Records what EuPago confirms. Runs inside EuPago's transaction, with the attempt locked: it never
 * throws because of the payable's state, so a payment it cannot apply goes to the commerce review
 * queue instead of being retried.
 */
class EupagoPayments implements HandlesEupagoPayments
{
    public function __construct(private readonly Commerce $commerce) {}

    public function canReceive(PaymentAttempt $attempt): bool
    {
        return $attempt->payable instanceof Payable;
    }

    public function received(PaymentAttempt $attempt, ConfirmedPayment $payment): void
    {
        /** @var Model&Payable $payable */
        $payable = $attempt->payable;
        [$locked] = $this->commerce->lockPayables([$payable]);
        $reason = $this->reviewReason($locked, $payment->amountCents, $this->commerce->due($locked, true));

        $record = new Payment([
            'source' => PaymentSource::Eupago,
            'method' => PaymentMethod::from($payment->method->value),
            'external_id' => $payment->trid,
            'idempotency_key' => "eupago:{$payment->trid}",
            'currency' => $locked->currency(),
            'amount_cents' => $payment->amountCents,
            'paid_at' => $payment->paidAt,
            'needs_review' => $reason !== null,
            'review_reason' => $reason,
        ]);
        $record->payable()->associate($locked);
        $record->save();

        if ($reason !== null) {
            event(new PaymentNeedsReview($record));

            return;
        }

        $this->commerce->allocateLocked($record, $locked, $payment->amountCents);

        // Task 5: DB::afterCommit(Billing::sync(...)) here
    }

    public function expired(PaymentAttempt $attempt): void
    {
        $payable = $attempt->payable;

        if (! $payable instanceof Payable) {
            return;
        }

        // A plain read: locking a sibling attempt could deadlock with its own webhook, which holds it
        // and then wants the payable. A race here only leaves the payable open, never closes it wrongly.
        $open = PaymentAttempt::query()
            ->where('payable_type', $attempt->payable_type)
            ->where('payable_id', $attempt->payable_id)
            ->whereKeyNot($attempt->getKey())
            ->whereIn('status', [AttemptStatus::Creating, AttemptStatus::Pending, AttemptStatus::Unknown, AttemptStatus::Paid])
            ->exists();

        if ($open) {
            return;
        }

        [$locked] = $this->commerce->lockPayables([$payable]);
        $locked->onExpired();
    }

    /**
     * Why the payment cannot be applied to the locked payable as a whole, or null when it can.
     */
    private function reviewReason(Model&Payable $payable, int $amountCents, int $dueCents): ?string
    {
        return match (true) {
            ! $payable->canReceivePayment() => 'not_accepting_payments',
            $payable->billingFlow() === BillingFlow::PayFirst && $amountCents !== $dueCents => 'amount_mismatch',
            $amountCents > $dueCents => 'exceeds_due',
            default => null,
        };
    }
}
