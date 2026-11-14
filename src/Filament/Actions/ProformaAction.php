<?php

namespace Noop\Commerce\Filament\Actions;

use Filament\Actions\Action;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Str;
use Noop\Commerce\Enums\OrderStatus;
use Noop\Commerce\Filament\Actions\Concerns\CallsCommerce;
use Noop\Commerce\Models\Order;
use Noop\Moloni\Facades\Moloni;

/**
 * Issues a pro-forma of a confirmed order in Moloni (Moloni::proforma()).
 */
class ProformaAction extends Action
{
    use CallsCommerce;

    public static function getDefaultName(): ?string
    {
        return 'proforma';
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCommerceAction();

        $this->label(__('Pro-forma'))
            ->icon(Heroicon::OutlinedDocumentDuplicate)
            ->color('gray')
            ->visible(fn (?Order $record): bool => in_array($record?->status, [OrderStatus::PendingPayment, OrderStatus::PartiallyPaid, OrderStatus::Paid], true))
            ->requiresConfirmation()
            ->modalDescription(__('A new pro-forma of this order is issued in Moloni.'))
            ->successNotificationTitle(__('A pro-forma was requested.'))
            ->action(fn (Order $record) => $this->callCommerce(fn () => Moloni::proforma($record, (string) Str::ulid())));
    }
}
