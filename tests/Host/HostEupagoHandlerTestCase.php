<?php

namespace Noop\Commerce\Tests\Host;

use Noop\Commerce\Tests\Fixtures\HostEupagoServiceProvider;
use Noop\Commerce\Tests\TestCase;

/**
 * A host that bound its own EuPago handler before the package registered.
 */
abstract class HostEupagoHandlerTestCase extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [HostEupagoServiceProvider::class, ...parent::getPackageProviders($app)];
    }
}
