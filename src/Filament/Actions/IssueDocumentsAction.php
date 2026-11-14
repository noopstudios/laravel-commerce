<?php

namespace Noop\Commerce\Filament\Actions;

use Filament\Actions\Action;
use Filament\Support\Icons\Heroicon;
use Noop\Commerce\Billing;
use Noop\Commerce\Enums\OrderStatus;
use Noop\Commerce\Filament\Actions\Concerns\CallsCommerce;
use Noop\Commerce\Models\Order;

/**
 * Requests whatever Moloni document the order and its payments are missing, a failed one included
 * (Billing::sync() with $retryFailed): the panel's only way to request a document again.
 */
class IssueDocumentsAction extends Action
{
    use CallsCommerce;

    public static function getDefaultName(): ?string
    {
        return 'issueDocuments';
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCommerceAction();

        $this->label(__('Issue documents'))
            ->icon(Heroicon::OutlinedDocumentText)
            ->color('warning')
            ->visible(fn (?Order $record): bool => $record !== null && $record->status !== OrderStatus::Draft && $record->total_cents > 0)
            ->requiresConfirmation()
            ->modalDescription(__('Moloni is asked for every document this order and its payments are missing, including those that failed. A failed invoice or invoice-receipt is requested again from the order\'s current data.'))
            ->successNotificationTitle(__('The missing documents were requested.'))
            ->action(fn (Order $record) => $this->callCommerce(fn () => app(Billing::class)->sync($record, retryFailed: true)));
    }
}
