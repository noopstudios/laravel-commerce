<?php

namespace Noop\Commerce\Filament\Actions;

use DateTimeInterface;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Model;
use Noop\Commerce\Commerce;
use Noop\Commerce\Enums\BillingFlow;
use Noop\Commerce\Eupago\EupagoBridge;
use Noop\Commerce\Filament\Actions\Concerns\CallsCommerce;
use Noop\Commerce\Models\Order;

/**
 * Requests a Multibanco reference for the order's due (Commerce::multibanco()); in circuit B, for
 * part of it if an amount is given.
 */
class MultibancoAction extends Action
{
    use CallsCommerce;

    public static function getDefaultName(): ?string
    {
        return 'multibanco';
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCommerceAction();

        $this->label(__('Generate Multibanco reference'))
            ->icon(Heroicon::OutlinedBanknotes)
            ->visible(fn (?Order $record): bool => $record?->canReceivePayment() === true && EupagoBridge::installed())
            ->requiresConfirmation()
            ->modalDescription(__('A Multibanco reference is requested from EuPago. Send it to the customer.'))
            ->schema(fn (Order $record): array => self::amountField($record))
            ->action(function (Order $record, array $data): void {
                $attempt = $this->callCommerce(fn () => app(Commerce::class)->multibanco($record, self::cents($data['amount'] ?? null)));

                Notification::make()->success()
                    ->title(__('Multibanco reference generated'))
                    ->body(__('Entity :entity, reference :reference, :amount, valid until :expires.', self::attemptDetails($attempt)))
                    ->persistent()
                    ->send();
            });
    }

    /**
     * The optional amount of a partial request, circuit B only.
     *
     * @return list<TextInput>
     */
    public static function amountField(Order $order): array
    {
        if ($order->billing_flow !== BillingFlow::InvoiceFirst) {
            return [];
        }

        return [
            TextInput::make('amount')
                ->label(__('Amount (€)'))
                ->helperText(__('Leave empty to request the whole due.'))
                ->numeric()
                ->minValue(0.01),
        ];
    }

    /**
     * @return array{entity: string, reference: string, amount: string, expires: string}
     */
    public static function attemptDetails(Model $attempt): array
    {
        $expires = $attempt->getAttribute('expires_at');

        return [
            'entity' => (string) $attempt->getAttribute('entity'),
            'reference' => (string) $attempt->getAttribute('reference'),
            'amount' => self::money((int) $attempt->getAttribute('amount_cents')),
            'expires' => $expires instanceof DateTimeInterface ? $expires->format('Y-m-d H:i') : '-',
        ];
    }
}
