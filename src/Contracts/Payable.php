<?php

namespace Noop\Commerce\Contracts;

use Noop\Commerce\Enums\BillingFlow;

/**
 * An Eloquent model that can be paid (the package's Order, or a host model).
 */
interface Payable
{
    public function totalCents(): int;

    public function currency(): string;

    public function billingFlow(): BillingFlow;

    /**
     * Whether automatic payments are accepted now.
     */
    public function canReceivePayment(): bool;

    /**
     * Called inside the transaction, with the row locked, after each allocation or void.
     */
    public function onDueChanged(int $dueCents): void;

    /**
     * Called inside the transaction when the last attempt ended without payment.
     */
    public function onExpired(): void;

    public function paymentLabel(): string;
}
