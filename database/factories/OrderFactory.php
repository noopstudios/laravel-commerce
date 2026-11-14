<?php

namespace Noop\Commerce\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Noop\Commerce\Enums\BillingFlow;
use Noop\Commerce\Enums\OrderStatus;
use Noop\Commerce\Models\Order;

/**
 * @extends Factory<Order>
 */
class OrderFactory extends Factory
{
    protected $model = Order::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'billing_name' => fake()->name(),
            'billing_email' => fake()->safeEmail(),
            'billing_country' => 'PT',
            'status' => OrderStatus::Draft,
            'billing_flow' => BillingFlow::PayFirst,
        ];
    }
}
