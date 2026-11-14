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
     * Whether the payable accepts a payment now: required by recordPayment(), multibanco(),
     * mbway() and for an automatic allocation of an EuPago payment. Only allocate() (the
     * administrator's decision) bypasses it.
     */
    public function canReceivePayment(): bool;

    /**
     * Called inside the transaction, with the row locked, after each allocation or void.
     */
    public function onDueChanged(int $dueCents): void;

    /**
     * Called inside the transaction when an expired Multibanco reference was the payable's last
     * open attempt.
     */
    public function onExpired(): void;

    public function paymentLabel(): string;
}
