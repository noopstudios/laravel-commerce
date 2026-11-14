<?php

namespace Noop\Commerce\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Noop\Commerce\Models\Order;
use Noop\Commerce\Models\OrderItem;

/**
 * @extends Factory<OrderItem>
 */
class OrderItemFactory extends Factory
{
    protected $model = OrderItem::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'order_id' => Order::factory(),
            'name' => fake()->words(3, true),
            'quantity' => 1,
            'unit_net_price' => '10.00000',
            'tax_rate' => '23.00',
            'subtotal_cents' => 1000,
            'tax_cents' => 230,
            'total_cents' => 1230,
        ];
    }
}
