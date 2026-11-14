<?php

namespace Noop\Commerce\Filament\Actions;

use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Noop\Commerce\Commerce;
use Noop\Commerce\Eupago\EupagoBridge;
use Noop\Commerce\Filament\Actions\Concerns\CallsCommerce;
use Noop\Commerce\Models\Order;

/**
 * Sends an MB WAY request to the customer's phone for the order's due (Commerce::mbway()); in
 * circuit B, for part of it if an amount is given.
 */
class MbwayAction extends Action
{
    use CallsCommerce;

    public static function getDefaultName(): ?string
    {
        return 'mbway';
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCommerceAction();

        $this->label(__('Request MB WAY'))
            ->icon(Heroicon::OutlinedDevicePhoneMobile)
            ->visible(fn (?Order $record): bool => $record?->canReceivePayment() === true && EupagoBridge::installed())
            ->schema(fn (Order $record): array => [
                TextInput::make('phone')
                    ->label(__('Phone'))
                    ->tel()
                    ->required()
                    ->default($record->billing_phone),
                ...MultibancoAction::amountField($record),
            ])
            ->action(function (Order $record, array $data): void {
                $attempt = $this->callCommerce(fn () => app(Commerce::class)->mbway($record, (string) $data['phone'], self::cents($data['amount'] ?? null)));

                Notification::make()->success()
                    ->title(__('MB WAY request sent'))
                    ->body(__('The customer has until :expires to accept :amount on the phone.', MultibancoAction::attemptDetails($attempt)))
                    ->send();
            });
    }
}
