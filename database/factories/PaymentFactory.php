<?php

namespace Noop\Commerce\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Noop\Commerce\Enums\PaymentMethod;
use Noop\Commerce\Enums\PaymentSource;
use Noop\Commerce\Models\Payment;

/**
 * @extends Factory<Payment>
 */
class PaymentFactory extends Factory
{
    protected $model = Payment::class;

    /**
     * A manual transfer; pass `for($payable, 'payable')` to attach it.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'source' => PaymentSource::Manual,
            'method' => PaymentMethod::Transfer,
            'idempotency_key' => (string) Str::ulid(),
            'amount_cents' => 1230,
            'paid_at' => now(),
        ];
    }
}
