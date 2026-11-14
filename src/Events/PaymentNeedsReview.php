<?php

namespace Noop\Commerce\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Noop\Commerce\Models\Payment;

class PaymentNeedsReview implements ShouldDispatchAfterCommit
{
    public function __construct(public readonly Payment $payment) {}
}
