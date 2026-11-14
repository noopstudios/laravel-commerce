<?php

namespace Noop\Commerce\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Noop\Commerce\Contracts\Purchasable;

/**
 * A host-app sellable model; it borrows the test users table, only its id matters.
 */
class Product extends Model implements Purchasable
{
    protected $table = 'test_users';

    protected $guarded = [];

    public function toOrderItem(): array
    {
        return ['name' => 'Workshop', 'reference' => 'WS-1', 'unit_net_price' => '20', 'tax_rate' => 23];
    }
}
