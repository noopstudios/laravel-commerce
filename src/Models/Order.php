<?php

namespace Noop\Commerce\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Noop\Commerce\Database\Factories\OrderFactory;
use Noop\Commerce\Enums\BillingFlow;
use Noop\Commerce\Enums\OrderStatus;

/**
 * @property int $id
 * @property string|null $number
 * @property string $public_token
 * @property string|null $buyer_type
 * @property int|string|null $buyer_id
 * @property string|null $billing_name
 * @property string|null $billing_email
 * @property string|null $billing_vat
 * @property string|null $billing_phone
 * @property string|null $billing_address
 * @property string|null $billing_postal_code
 * @property string|null $billing_city
 * @property string|null $billing_country
 * @property bool $is_business
 * @property string $currency
 * @property int $subtotal_cents
 * @property int $tax_cents
 * @property int $total_cents
 * @property OrderStatus $status
 * @property BillingFlow $billing_flow
 * @property int $payment_terms_days
 * @property string|null $customer_reference
 * @property Carbon|null $paid_at
 * @property string|null $notes
 * @property array<string, mixed>|null $meta
 */
class Order extends Model
{
    /** @use HasFactory<OrderFactory> */
    use HasFactory;

    // status, paid_at, number, public_token and the totals are written by the package only.
    protected $fillable = [
        'buyer_type', 'buyer_id',
        'billing_name', 'billing_email', 'billing_vat', 'billing_phone',
        'billing_address', 'billing_postal_code', 'billing_city', 'billing_country',
        'is_business', 'currency', 'billing_flow', 'payment_terms_days',
        'customer_reference', 'notes', 'meta',
    ];

    protected static function newFactory(): OrderFactory
    {
        return OrderFactory::new();
    }

    protected static function booted(): void
    {
        static::creating(function (self $order) {
            $order->public_token = (string) Str::ulid();
        });

        static::created(function (self $order) {
            $order->number = config('commerce.order_prefix').'-'.$order->id;
            $order->saveQuietly();
        });
    }

    protected function casts(): array
    {
        return [
            'status' => OrderStatus::class,
            'billing_flow' => BillingFlow::class,
            'is_business' => 'boolean',
            'subtotal_cents' => 'integer',
            'tax_cents' => 'integer',
            'total_cents' => 'integer',
            'payment_terms_days' => 'integer',
            'paid_at' => 'datetime',
            'meta' => 'array',
        ];
    }

    /**
     * A stable alias, so the keys Moloni stores do not depend on the class name.
     */
    public function getMorphClass(): string
    {
        return 'order';
    }

    /**
     * @return HasMany<OrderItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function buyer(): MorphTo
    {
        return $this->morphTo();
    }
}
