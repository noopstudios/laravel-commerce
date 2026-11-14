<?php

namespace Noop\Commerce\Tests\Fixtures;

use Illuminate\Support\ServiceProvider;
use Noop\Eupago\Contracts\HandlesEupagoPayments;

class HostEupagoServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(HandlesEupagoPayments::class, HostEupagoHandler::class);
    }
}
