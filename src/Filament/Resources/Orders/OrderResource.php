<?php

namespace Noop\Commerce\Filament\Resources\Orders;

use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Noop\Commerce\Commerce;
use Noop\Commerce\Enums\BillingFlow;
use Noop\Commerce\Enums\OrderStatus;
use Noop\Commerce\Filament\Resources\Orders\Pages\ListOrders;
use Noop\Commerce\Filament\Resources\Orders\Pages\ViewOrder;
use Noop\Commerce\Filament\Resources\Orders\Schemas\OrderInfolist;
use Noop\Commerce\Filament\Resources\Orders\Tables\OrdersTable;
use Noop\Commerce\Models\Order;
use Noop\Commerce\Models\Payment;
use Noop\Commerce\Models\PaymentAllocation;
use Noop\Moloni\Enums\DocumentStatus;
use Noop\Moloni\Enums\DocumentType;
use Noop\Moloni\Models\InvoiceDocument;

/**
 * The orders, read-only: the host's checkout creates them, and they change only through the
 * actions. Access follows OrderPolicy.
 */
class OrderResource extends Resource
{
    protected static ?string $slug = 'commerce-orders';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShoppingBag;

    protected static ?string $recordTitleAttribute = 'number';

    public static function getModel(): string
    {
        return Commerce::orderModel();
    }

    public static function getModelLabel(): string
    {
        return __('Order');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Orders');
    }

    public static function infolist(Schema $schema): Schema
    {
        return OrderInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return OrdersTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListOrders::route('/'),
            'view' => ViewOrder::route('/{record}'),
        ];
    }

    /**
     * The order's payments: those allocated to it and those that arrived for it (under review).
     *
     * @return Collection<int, Payment>
     */
    public static function payments(Order $order): Collection
    {
        return Payment::query()
            ->where(fn ($query) => $query
                ->whereIn('id', self::allocations($order)->select('payment_id'))
                ->orWhere(fn ($query) => $query->where('payable_type', $order->getMorphClass())->where('payable_id', $order->getKey())))
            ->with('allocations')
            ->orderBy('id')
            ->get();
    }

    /**
     * @return Builder<PaymentAllocation>
     */
    public static function allocations(Order $order): Builder
    {
        return PaymentAllocation::query()
            ->where('payable_type', $order->getMorphClass())
            ->where('payable_id', $order->getKey());
    }

    /**
     * Whether a document the order should have by now has no live row: its invoice or
     * invoice-receipt, or the receipt of a payment once the invoice is issued.
     */
    public static function documentMissing(Order $order): bool
    {
        $live = fn (array $types, string $type, int|string $id) => InvoiceDocument::query()
            ->whereIn('type', $types)
            ->whereIn('status', DocumentStatus::live())
            ->where('invoiceable_type', $type)
            ->where('invoiceable_id', $id);

        if ($order->total_cents <= 0) {
            return false;
        }

        if ($order->billing_flow === BillingFlow::PayFirst) {
            return $order->status === OrderStatus::Paid
                && ! $live([DocumentType::Invoice, DocumentType::InvoiceReceipt], $order->getMorphClass(), $order->getKey())->exists();
        }

        if (! in_array($order->status, [OrderStatus::PendingPayment, OrderStatus::PartiallyPaid, OrderStatus::Paid], true)) {
            return false;
        }

        $invoice = $live([DocumentType::Invoice, DocumentType::InvoiceReceipt], $order->getMorphClass(), $order->getKey())->first();

        if ($invoice === null) {
            return true;
        }

        return $invoice->status === DocumentStatus::Issued && self::payments($order)
            ->filter(fn (Payment $payment) => $payment->voided_at === null && $payment->allocations->contains(
                fn (PaymentAllocation $allocation) => $allocation->payable_type === $order->getMorphClass() && $allocation->payable_id == $order->getKey(),
            ))
            ->contains(fn (Payment $payment) => ! $live([DocumentType::Receipt], $payment->getMorphClass(), $payment->getKey())->exists());
    }
}
