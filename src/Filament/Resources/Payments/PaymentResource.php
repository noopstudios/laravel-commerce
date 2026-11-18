<?php

namespace Noop\Commerce\Filament\Resources\Payments;

use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Noop\Commerce\Contracts\Payable;
use Noop\Commerce\Filament\Resources\Payments\Pages\ListPayments;
use Noop\Commerce\Filament\Resources\Payments\Pages\ViewPayment;
use Noop\Commerce\Filament\Resources\Payments\Schemas\PaymentInfolist;
use Noop\Commerce\Filament\Resources\Payments\Tables\PaymentsTable;
use Noop\Commerce\Models\Payment;

/**
 * The payments, read-only, opening on the review queue: they are recorded through the order actions
 * and EuPago, and change only through the actions. Access follows PaymentPolicy.
 */
class PaymentResource extends Resource
{
    protected static ?string $model = Payment::class;

    protected static ?string $slug = 'commerce-payments';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCurrencyEuro;

    public static function getModelLabel(): string
    {
        return __('Payment');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Payments');
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with('allocations');
    }

    public static function infolist(Schema $schema): Schema
    {
        return PaymentInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return PaymentsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPayments::route('/'),
            'view' => ViewPayment::route('/{record}'),
        ];
    }

    /**
     * What a payable is called in the panel: an order's number.
     */
    public static function payableLabel(?Model $payable): ?string
    {
        return $payable instanceof Payable ? $payable->paymentLabel() : null;
    }

    /**
     * What a review reason is called in the panel; an unknown reason is shown as it is.
     */
    public static function reviewReasonLabel(?string $reason): ?string
    {
        $label = match ($reason) {
            'currency_mismatch' => __('Different currency'),
            'not_accepting_payments' => __('No longer accepting payments'),
            'amount_mismatch' => __('Amount differs from the amount due'),
            'exceeds_due' => __('Amount exceeds the amount due'),
            default => $reason,
        };

        return is_string($label) ? $label : $reason;
    }

    public static function allocatedCents(Payment $payment): int
    {
        return (int) $payment->allocations->sum('amount_cents');
    }
}
