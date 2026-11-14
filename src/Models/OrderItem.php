<?php

namespace Noop\Commerce\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Noop\Commerce\Commerce;
use Noop\Commerce\Database\Factories\OrderItemFactory;
use Noop\Commerce\Enums\OrderStatus;
use Noop\Commerce\Exceptions\CommerceException;
use Noop\Moloni\Data\InvoiceLine;

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

    protected static function booted(): void
    {
        // Lines are frozen once the order leaves draft; the status is read from the database.
        $guard = function (self $item) {
            $orderIds = [$item->order_id];

            // Moving a line out of or into another order changes both orders.
            if ($item->exists && $item->isDirty('order_id')) {
                $orderIds[] = $item->getOriginal('order_id');
            }

            foreach ($orderIds as $orderId) {
                if (Commerce::orderModel()::query()->whereKey($orderId)->value('status') !== OrderStatus::Draft) {
                    throw new CommerceException('The lines of an order can only change while it is a draft.');
                }
            }
        };

        static::deleting($guard);

        static::saving(function (self $item) use ($guard) {
            $guard($item);

            // Line amounts as Moloni will compute them, so the amount charged is the amount invoiced.
            $line = $item->toInvoiceLine();

            $item->subtotal_cents = $line->lineNetCents();
            $item->tax_cents = $line->lineTaxCents();
            $item->total_cents = $item->subtotal_cents + $item->tax_cents;
        });
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

    public function toInvoiceLine(): InvoiceLine
    {
        return new InvoiceLine(
            name: $this->name,
            quantity: (float) $this->quantity,
            unitNetPrice: $this->unit_net_price,
            taxRate: (float) $this->tax_rate,
            exemptionReason: $this->exemption_reason,
            reference: $this->reference,
            productId: $this->moloni_product_id,
            summary: $this->description ?? '',
        );
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Commerce::orderModel(), 'order_id');
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function purchasable(): MorphTo
    {
        return $this->morphTo();
    }
}
