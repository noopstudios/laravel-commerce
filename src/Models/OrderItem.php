<?php

namespace Noop\Commerce\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Noop\Commerce\Database\Factories\OrderItemFactory;

/**
 * @property int $id
 * @property int $order_id
 * @property string|null $purchasable_type
 * @property int|string|null $purchasable_id
 * @property string|null $reference
 * @property string $name
 * @property string|null $description
 * @property numeric-string $quantity
 * @property numeric-string $unit_net_price
 * @property numeric-string $tax_rate
 * @property string|null $exemption_reason
 * @property int|null $moloni_product_id
 * @property int $subtotal_cents
 * @property int $tax_cents
 * @property int $total_cents
 * @property array<string, mixed>|null $meta
 */
class OrderItem extends Model
{
    /** @use HasFactory<OrderItemFactory> */
    use HasFactory;

    protected $guarded = [];

    protected static function newFactory(): OrderItemFactory
    {
        return OrderItemFactory::new();
    }

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
            'unit_net_price' => 'decimal:5',
            'tax_rate' => 'decimal:2',
            'moloni_product_id' => 'integer',
            'subtotal_cents' => 'integer',
            'tax_cents' => 'integer',
            'total_cents' => 'integer',
            'meta' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        /** @var class-string<Order> $order */
        $order = config('commerce.models.order');

        return $this->belongsTo($order);
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function purchasable(): MorphTo
    {
        return $this->morphTo();
    }
}
