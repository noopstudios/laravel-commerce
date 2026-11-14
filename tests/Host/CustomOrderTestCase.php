<?php

namespace Noop\Commerce\Tests\Host;

use Noop\Commerce\Tests\Fixtures\CustomOrder;
use Noop\Commerce\Tests\TestCase;

/**
 * A host that configured its own order model before the package booted.
 */
abstract class CustomOrderTestCase extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('commerce.models.order', CustomOrder::class);
    }
}
