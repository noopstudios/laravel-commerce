<?php

namespace Noop\Commerce\Tests\Fixtures;

use Noop\Eupago\Contracts\HandlesEupagoPayments;
use Noop\Eupago\Data\ConfirmedPayment;
use Noop\Eupago\Models\PaymentAttempt;

/**
 * A host's own EuPago handler, bound by a provider registered before the package's.
 */
class HostEupagoHandler implements HandlesEupagoPayments
{
    public function canReceive(PaymentAttempt $attempt): bool
    {
        return false;
    }

    public function received(PaymentAttempt $attempt, ConfirmedPayment $payment): void {}

    public function expired(PaymentAttempt $attempt): void {}
}
