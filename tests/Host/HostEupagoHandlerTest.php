<?php

use Noop\Commerce\Tests\Fixtures\HostEupagoHandler;
use Noop\Commerce\Tests\Host\HostEupagoHandlerTestCase;
use Noop\Eupago\Contracts\HandlesEupagoPayments;

uses(HostEupagoHandlerTestCase::class);

it('keeps the EuPago handler the host bound', function () {
    expect(app(HandlesEupagoPayments::class))->toBeInstanceOf(HostEupagoHandler::class);
});
