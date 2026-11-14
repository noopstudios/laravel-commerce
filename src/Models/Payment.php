<?php

namespace Noop\Commerce\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;
use Noop\Commerce\Database\Factories\PaymentFactory;
use Noop\Commerce\Enums\PaymentMethod;
use Noop\Commerce\Enums\PaymentSource;

/**
 * Money received. The allocations say what it paid; the payable is what it arrived for.
 *
 * @property int $id
 * @property string|null $payable_type
 * @property int|string|null $payable_id
 * @property PaymentSource $source
 * @property PaymentMethod $method
 * @property string|null $external_id
 * @property string $idempotency_key
 * @property string $currency
 * @property int $amount_cents
 * @property Carbon $paid_at
 * @property int|string|null $recorded_by_id
 * @property string|null $notes
 * @property bool $needs_review
 * @property string|null $review_reason
 * @property Carbon|null $voided_at
 * @property int|string|null $voided_by_id
 * @property string|null $void_reason
 */
class Payment extends Model
{
    /** @use HasFactory<PaymentFactory> */
    use HasFactory;

    protected $guarded = [];

    protected static function newFactory(): PaymentFactory
    {
        return PaymentFactory::new();
    }

    protected function casts(): array
    {
        return [
            'source' => PaymentSource::class,
            'method' => PaymentMethod::class,
            'amount_cents' => 'integer',
            'paid_at' => 'datetime',
            'needs_review' => 'boolean',
            'voided_at' => 'datetime',
        ];
    }

    /**
     * A stable alias, so the keys Moloni stores do not depend on the class name.
     */
    public function getMorphClass(): string
    {
        return 'payment';
    }

    /**
     * @return HasMany<PaymentAllocation, $this>
     */
    public function allocations(): HasMany
    {
        return $this->hasMany(PaymentAllocation::class);
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function payable(): MorphTo
    {
        return $this->morphTo();
    }
}
