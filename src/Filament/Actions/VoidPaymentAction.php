<?php

namespace Noop\Commerce\Filament\Actions;

use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Support\Icons\Heroicon;
use Noop\Commerce\Commerce;
use Noop\Commerce\Enums\PaymentSource;
use Noop\Commerce\Filament\Actions\Concerns\CallsCommerce;
use Noop\Commerce\Models\Payment;
use Noop\Moloni\Enums\DocumentStatus;
use Noop\Moloni\Enums\DocumentType;
use Noop\Moloni\Models\InvoiceDocument;

/**
 * Voids a payment recorded by hand (Commerce::voidPayment()): the orders it paid owe it again. Not
 * while a live receipt of it exists: cancel the receipt in Moloni first.
 */
class VoidPaymentAction extends Action
{
    use CallsCommerce;

    public static function getDefaultName(): ?string
    {
        return 'voidPayment';
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCommerceAction();

        $this->label(__('Void'))
            ->icon(Heroicon::OutlinedNoSymbol)
            ->color('danger')
            ->visible(fn (?Payment $record): bool => $record !== null
                && $record->source === PaymentSource::Manual
                && $record->voided_at === null
                && ! self::hasLiveReceipt($record))
            ->requiresConfirmation()
            ->modalDescription(__('The payment stops counting and the orders it paid owe its amount again.'))
            ->schema([
                Textarea::make('reason')
                    ->label(__('Reason for voiding'))
                    ->required()
                    ->maxLength(1000),
            ])
            ->successNotificationTitle(__('The payment was voided.'))
            ->action(fn (Payment $record, array $data) => $this->callCommerce(fn () => app(Commerce::class)->voidPayment($record, (string) $data['reason'], self::user())));
    }

    private static function hasLiveReceipt(Payment $payment): bool
    {
        return InvoiceDocument::query()
            ->where('type', DocumentType::Receipt)
            ->whereIn('status', DocumentStatus::live())
            ->where('invoiceable_type', $payment->getMorphClass())
            ->where('invoiceable_id', $payment->getKey())
            ->exists();
    }
}
