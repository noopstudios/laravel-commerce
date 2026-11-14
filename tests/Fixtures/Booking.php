<?php

namespace Noop\Commerce\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Noop\Commerce\Contracts\Payable;
use Noop\Commerce\Enums\BillingFlow;
use Noop\Moloni\Contracts\Invoiceable;
use Noop\Moloni\Data\CustomerData;
use Noop\Moloni\Data\InvoiceData;
use Noop\Moloni\Data\InvoiceLine;

/**
 * A host model paid and invoiced in circuit A (level 3): a booking of 12.30 (10.00 + 23% VAT).
 *
 * @property int $id
 * @property string $status
 */
class Booking extends Model implements Invoiceable, Payable
{
    protected $guarded = [];

    /** @var array<string, mixed> */
    protected $attributes = ['status' => 'awaiting_payment'];

    public function totalCents(): int
    {
        return 1230;
    }

    public function currency(): string
    {
        return 'EUR';
    }

    public function billingFlow(): BillingFlow
    {
        return BillingFlow::PayFirst;
    }

    public function canReceivePayment(): bool
    {
        return $this->status === 'awaiting_payment';
    }

    public function onDueChanged(int $dueCents): void
    {
        $this->forceFill(['status' => $dueCents === 0 ? 'paid' : 'awaiting_payment'])->save();
    }

    public function onExpired(): void {}

    public function paymentLabel(): string
    {
        return "Booking {$this->id}";
    }

    public function invoiceReference(): string
    {
        return "BK-{$this->id}";
    }

    public function customerKey(): string
    {
        return "K{$this->id}";
    }

    public function toInvoiceData(): InvoiceData
    {
        return new InvoiceData(
            customer: new CustomerData(key: $this->customerKey(), vat: '509999999', name: 'Guest', email: null, address: null, postalCode: null, city: null, isBusiness: true),
            lines: [new InvoiceLine('Room', 1, '10', 23)],
        );
    }
}
