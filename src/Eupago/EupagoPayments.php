<?php

namespace Noop\Commerce\Eupago;

use Illuminate\Database\Eloquent\Model;
use Noop\Commerce\Billing;
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
use Noop\Eupago\Enums\PaymentMethod as EupagoMethod;
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

        // A trid already recorded for another attempt: storing it again would break the unique key
        // and retry the webhook forever, so the payment goes to review without it.
        $duplicate = Payment::query()->where('source', PaymentSource::Eupago)->where('external_id', $payment->trid)->exists();

        if ($duplicate) {
            $reason = 'duplicate_transaction';
        }

        $record = new Payment([
            'source' => PaymentSource::Eupago,
            'method' => PaymentMethod::from($payment->method->value),
            'external_id' => $duplicate ? null : $payment->trid,
            // The attempt is what is paid once; its trid may repeat.
            'idempotency_key' => "eupago:{$attempt->identifier}",
            'currency' => 'EUR',
            'amount_cents' => $payment->amountCents,
            'paid_at' => $payment->paidAt,
            'needs_review' => $reason !== null,
            'review_reason' => $reason,
            'notes' => $duplicate ? "EuPago transaction {$payment->trid} was already recorded." : null,
        ]);
        $record->payable()->associate($locked);
        $record->save();

        if ($reason !== null) {
            event(new PaymentNeedsReview($record));

            return;
        }

        $this->commerce->allocateLocked($record, $locked, $payment->amountCents);

        // After EuPago's transaction commits: invoicing never fails the webhook.
        Billing::syncAfterCommit($locked);
    }

    public function expired(PaymentAttempt $attempt): void
    {
        $payable = $attempt->payable;

        if (! $payable instanceof Payable) {
            return;
        }

        // Only an expired Multibanco reference closes the payable: after an MB WAY request expires or
        // is declined, or any attempt fails or is abandoned, the customer may request another payment.
        if ($attempt->method !== EupagoMethod::Multibanco || $attempt->status !== AttemptStatus::Expired) {
            return;
        }

        // A plain read: locking a sibling attempt could deadlock with its own webhook, which holds it
        // and then wants the payable. A race here can leave the payable open, or expire it while a new
        // request is being created; a payment that then arrives goes to review.
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
            $payable->currency() !== 'EUR' => 'currency_mismatch',
            ! $payable->canReceivePayment() => 'not_accepting_payments',
            $payable->billingFlow() === BillingFlow::PayFirst && $amountCents !== $dueCents => 'amount_mismatch',
            $amountCents > $dueCents => 'exceeds_due',
            default => null,
        };
    }
}
