<?php

namespace Noop\Commerce\Filament\Actions;

use Filament\Actions\Action;
use Filament\Support\Icons\Heroicon;
use Noop\Commerce\Enums\OrderStatus;
use Noop\Commerce\Filament\Actions\Concerns\CallsCommerce;
use Noop\Commerce\Models\Order;

/**
 * Cancels an order nobody paid (Order::cancel()).
 */
class CancelOrderAction extends Action
{
    use CallsCommerce;

    public static function getDefaultName(): ?string
    {
        return 'cancelOrder';
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCommerceAction();

        $this->label(__('Cancel order'))
            ->icon(Heroicon::OutlinedXCircle)
            ->color('danger')
            ->visible(fn (?Order $record): bool => in_array($record?->status, [OrderStatus::Draft, OrderStatus::PendingPayment, OrderStatus::Expired], true))
            ->requiresConfirmation()
            ->modalDescription(__('The order is cancelled and stops accepting payments. Its Moloni documents are not touched.'))
            ->successNotificationTitle(__('The order was cancelled.'))
            ->action(fn (Order $record) => $this->callCommerce(fn () => $record->cancel()));
    }
}
