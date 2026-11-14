<?php

namespace Noop\Commerce\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Database\Eloquent\Model;

class PayableSettled implements ShouldDispatchAfterCommit
{
    public function __construct(public readonly Model $payable) {}
}
