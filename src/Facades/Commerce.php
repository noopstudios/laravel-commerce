<?php

namespace Noop\Commerce\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static void ignoreOrderMigrations()
 * @method static int dueCents(\Illuminate\Database\Eloquent\Model&\Noop\Commerce\Contracts\Payable $payable)
 * @method static \Noop\Commerce\Models\Payment recordPayment(array<array{0: \Illuminate\Database\Eloquent\Model&\Noop\Commerce\Contracts\Payable, 1: int}> $allocations, \Noop\Commerce\Enums\PaymentMethod $method, \Carbon\CarbonInterface $paidAt, ?\Illuminate\Database\Eloquent\Model $recordedBy = null, ?string $notes = null, ?string $idempotencyKey = null)
 * @method static \Noop\Commerce\Models\PaymentAllocation allocate(\Noop\Commerce\Models\Payment $payment, \Illuminate\Database\Eloquent\Model&\Noop\Commerce\Contracts\Payable $payable, ?int $amountCents = null)
 * @method static void resolveReview(\Noop\Commerce\Models\Payment $payment, string $note)
 * @method static \Illuminate\Database\Eloquent\Model multibanco(\Illuminate\Database\Eloquent\Model&\Noop\Commerce\Contracts\Payable $payable, ?int $amountCents = null)
 * @method static \Illuminate\Database\Eloquent\Model mbway(\Illuminate\Database\Eloquent\Model&\Noop\Commerce\Contracts\Payable $payable, string $phone, ?int $amountCents = null)
 * @method static void voidPayment(\Noop\Commerce\Models\Payment $payment, string $reason, ?\Illuminate\Database\Eloquent\Model $by = null)
 *
 * @see \Noop\Commerce\Commerce
 */
class Commerce extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Noop\Commerce\Commerce::class;
    }
}
