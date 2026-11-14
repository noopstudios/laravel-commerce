<?php

namespace Noop\Commerce\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use LogicException;
use Noop\Commerce\Contracts\Payable;
use Noop\Commerce\Contracts\Purchasable;
use Noop\Commerce\Database\Factories\OrderFactory;
use Noop\Commerce\Enums\BillingFlow;
use Noop\Commerce\Enums\OrderStatus;
use Noop\Commerce\Events\PayableExpired;
use Noop\Commerce\Events\PayableSettled;
use Noop\Commerce\Exceptions\CommerceException;
use Noop\Moloni\Contracts\Invoiceable;
use Noop\Moloni\Data\CustomerData;
use Noop\Moloni\Data\InvoiceData;

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
 * @property Carbon|null $created_at
 */
class Order extends Model implements Invoiceable, Payable
{
    /** @use HasFactory<OrderFactory> */
    use HasFactory;

    // Explicit, so a host subclass keeps the package's table.
    protected $table = 'orders';

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => 'draft',
        'billing_flow' => 'pay_first',
        'currency' => 'EUR',
        'subtotal_cents' => 0,
        'tax_cents' => 0,
        'total_cents' => 0,
    ];

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

        // The billing terms are part of what was agreed at confirmation.
        static::saving(function (self $order) {
            if ($order->exists && $order->getRawOriginal('status') !== OrderStatus::Draft->value && $order->isDirty(['billing_flow', 'currency', 'payment_terms_days'])) {
                throw new CommerceException("Order {$order->number} billing flow, currency and payment terms are frozen once it leaves draft.");
            }
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
        return $this->hasMany(OrderItem::class, 'order_id');
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function buyer(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Add a line to a draft order; the order totals follow.
     * The quantity is the argument when given, else the item's own `quantity`, else 1.
     *
     * @param  Purchasable|array<string, mixed>  $item
     */
    public function addItem(Purchasable|array $item, ?float $quantity = null): OrderItem
    {
        return $this->getConnection()->transaction(function () use ($item, $quantity) {
            $order = static::query()->lockForUpdate()->whereKey($this->getKey())->firstOrFail();

            if ($order->status !== OrderStatus::Draft) {
                throw new CommerceException("Order {$order->number} is not a draft.");
            }

            $attributes = is_array($item) ? $item : $item->toOrderItem();
            $attributes['quantity'] = (string) ($quantity ?? $attributes['quantity'] ?? 1);

            $line = $order->items()->make($attributes);

            if ($item instanceof Purchasable && $item instanceof Model) {
                $line->purchasable()->associate($item);
            }

            $line->save();

            $order->forceFill($order->sumLines())->save();
            $this->setRawAttributes($order->getAttributes(), true);
            $this->unsetRelations();

            return $line;
        });
    }

    /**
     * Close the draft: pending payment, or paid at once when there is nothing to pay.
     */
    public function confirm(): void
    {
        $this->getConnection()->transaction(function () {
            $order = static::query()->lockForUpdate()->whereKey($this->getKey())->firstOrFail();

            if ($order->status !== OrderStatus::Draft) {
                throw new CommerceException("Order {$order->number} is not a draft.");
            }

            if (! $order->items()->exists()) {
                throw new CommerceException("Order {$order->number} has no lines.");
            }

            $order->forceFill($order->sumLines());

            if ($order->total_cents === 0) {
                $order->forceFill(['status' => OrderStatus::Paid, 'paid_at' => now()])->save();

                event(new PayableSettled($order));
            } else {
                $order->forceFill(['status' => OrderStatus::PendingPayment])->save();
            }

            $this->setRawAttributes($order->getAttributes(), true);
            $this->unsetRelations();
        });

        // Task 5: Billing requests the circuit B invoice here, after commit.
    }

    public function cancel(): void
    {
        $this->getConnection()->transaction(function () {
            $order = static::query()->lockForUpdate()->whereKey($this->getKey())->firstOrFail();

            if (! in_array($order->status, [OrderStatus::Draft, OrderStatus::PendingPayment, OrderStatus::Expired], true)) {
                throw new CommerceException("Order {$order->number} cannot be cancelled while {$order->status->value}.");
            }

            $hasPayments = PaymentAllocation::query()
                ->where('payable_type', $order->getMorphClass())
                ->where('payable_id', $order->getKey())
                ->whereHas('payment', fn ($payment) => $payment->whereNull('voided_at'))
                ->exists();

            if ($hasPayments) {
                throw new CommerceException("Order {$order->number} has payments allocated to it.");
            }

            $order->forceFill(['status' => OrderStatus::Cancelled])->save();

            $this->setRawAttributes($order->getAttributes(), true);
            $this->unsetRelations();
        });
    }

    public function totalCents(): int
    {
        return $this->total_cents;
    }

    public function currency(): string
    {
        return $this->currency;
    }

    public function billingFlow(): BillingFlow
    {
        return $this->billing_flow;
    }

    public function canReceivePayment(): bool
    {
        return in_array($this->status, [OrderStatus::PendingPayment, OrderStatus::PartiallyPaid], true);
    }

    /**
     * Also reopens an expired or cancelled order: that is how a late payment is applied.
     */
    public function onDueChanged(int $dueCents): void
    {
        if ($this->status === OrderStatus::Draft) {
            throw new LogicException("Order {$this->number} is a draft and cannot be paid.");
        }

        $status = match (true) {
            $dueCents === 0 => OrderStatus::Paid,
            $dueCents < $this->total_cents => OrderStatus::PartiallyPaid,
            default => OrderStatus::PendingPayment,
        };

        $this->forceFill([
            'status' => $status,
            'paid_at' => $status === OrderStatus::Paid ? ($this->paid_at ?? now()) : null,
        ])->save();
    }

    /**
     * Circuit A orders close when the last attempt expires; in circuit B the debt stays.
     */
    public function onExpired(): void
    {
        if ($this->billing_flow !== BillingFlow::PayFirst || $this->status !== OrderStatus::PendingPayment) {
            return;
        }

        $this->forceFill(['status' => OrderStatus::Expired])->save();

        event(new PayableExpired($this));
    }

    public function paymentLabel(): string
    {
        return (string) $this->number;
    }

    public function invoiceReference(): string
    {
        return (string) $this->number;
    }

    /**
     * Buyer keys start with a letter (the buyer type's initial), guest keys with a digit; two buyer types sharing an initial must override this.
     */
    public function customerKey(): string
    {
        if ($this->buyer_type === null) {
            return '0'.$this->getKey();
        }

        return strtoupper(substr(class_basename($this->buyer_type), 0, 1)).$this->buyer_id;
    }

    public function toInvoiceData(): InvoiceData
    {
        $buyerCreatedAt = $this->buyer?->getAttribute('created_at');

        return new InvoiceData(
            customer: new CustomerData(
                key: $this->customerKey(),
                vat: $this->billing_vat,
                name: $this->billing_name,
                email: $this->billing_email,
                address: $this->billing_address,
                postalCode: $this->billing_postal_code,
                city: $this->billing_city,
                country: $this->billing_country,
                isBusiness: $this->is_business,
                createdAt: $buyerCreatedAt instanceof CarbonInterface ? $buyerCreatedAt : $this->created_at,
            ),
            lines: array_values($this->items()->orderBy('id')->get()->map(fn (OrderItem $item) => $item->toInvoiceLine())->all()),
            currency: $this->currency,
            yourReference: $this->customer_reference,
            notes: $this->notes,
        );
    }

    /**
     * @return array{subtotal_cents: int, tax_cents: int, total_cents: int}
     */
    private function sumLines(): array
    {
        // From the invoice lines, never the stored columns, so the total is what Moloni will invoice.
        $lines = $this->items()->get()->map(fn (OrderItem $item) => $item->toInvoiceLine());
        $subtotal = (int) $lines->sum(fn ($line) => $line->lineNetCents());
        $tax = (int) $lines->sum(fn ($line) => $line->lineTaxCents());

        return ['subtotal_cents' => $subtotal, 'tax_cents' => $tax, 'total_cents' => $subtotal + $tax];
    }
}
