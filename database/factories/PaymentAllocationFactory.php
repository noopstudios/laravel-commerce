<?php

namespace Noop\Commerce\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Noop\Commerce\Models\Payment;
use Noop\Commerce\Models\PaymentAllocation;

/**
 * @extends Factory<PaymentAllocation>
 */
class PaymentAllocationFactory extends Factory
{
    protected $model = PaymentAllocation::class;

    /**
     * Pass `for($payable, 'payable')` to say what it paid.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'payment_id' => Payment::factory(),
            'amount_cents' => 1230,
        ];
    }
}
