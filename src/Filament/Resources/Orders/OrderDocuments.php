<?php

namespace Noop\Commerce\Filament\Resources\Orders;

use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\On;
use Livewire\Component;
use Noop\Commerce\Models\Order;
use Noop\Commerce\Models\Payment;
use Noop\Moloni\Enums\DocumentStatus;
use Noop\Moloni\Enums\DocumentType;
use Noop\Moloni\Filament\Actions\CancelDocumentAction;
use Noop\Moloni\Filament\Actions\CreditNoteAction;
use Noop\Moloni\Filament\Actions\DownloadPdfAction;
use Noop\Moloni\Filament\Actions\ReissueDocumentAction;
use Noop\Moloni\Models\InvoiceDocument;

/**
 * The Moloni documents of an order and of its payments (receipts belong to the payment), with
 * Moloni's own document actions, which follow Moloni's permissions. A failed document is requested
 * again with the order's "Issue documents" action, never with Moloni's retry: that would resend
 * frozen data even for a payment voided meanwhile. A pro-forma is never converted here: the package
 * issues the order's invoice or invoice-receipt itself.
 */
class OrderDocuments extends Component implements HasActions, HasSchemas, HasTable
{
    use InteractsWithActions;
    use InteractsWithSchemas;
    use InteractsWithTable;

    /** Dispatched after an order or document action, so the order page and this table reload. */
    public const CHANGED = 'commerce-order-changed';

    public Order $order;

    public function mount(Order $order): void
    {
        Gate::authorize('view', $order);

        $this->order = $order;
    }

    #[On(self::CHANGED)]
    public function reload(): void {}

    public function table(Table $table): Table
    {
        $payments = OrderResource::payments($this->order)->modelKeys();

        return $table
            ->query(fn () => InvoiceDocument::query()
                ->where(fn ($query) => $query->where('invoiceable_type', $this->order->getMorphClass())->where('invoiceable_id', $this->order->getKey()))
                ->orWhere(fn ($query) => $query->where('invoiceable_type', (new Payment)->getMorphClass())->whereIn('invoiceable_id', $payments)))
            ->defaultSort('id')
            ->paginated(false)
            ->emptyStateHeading(__('No documents yet.'))
            ->columns([
                TextColumn::make('type')->label(__('Type'))
                    ->formatStateUsing(fn (DocumentType $state): string => $state->getLabel()),
                TextColumn::make('document_number')->label(__('Number'))->placeholder('-'),
                TextColumn::make('status')->label(__('Status'))->badge()
                    ->formatStateUsing(fn (DocumentStatus $state): string => $state->getLabel())
                    ->color(fn (DocumentStatus $state): string => $state->getColor()),
                TextColumn::make('expected_total_cents')->label(__('Total'))
                    ->money(fn (InvoiceDocument $record): string => $record->currency, divideBy: 100),
                TextColumn::make('error_message')->label(__('Error'))->placeholder('-')->wrap(),
                TextColumn::make('created_at')->label(__('Created at'))->dateTime(),
            ])
            ->recordActions([
                DownloadPdfAction::make(),
                ReissueDocumentAction::make()->after(fn () => $this->dispatch(self::CHANGED)),
                CreditNoteAction::make()->after(fn () => $this->dispatch(self::CHANGED)),
                CancelDocumentAction::make()->after(fn () => $this->dispatch(self::CHANGED)),
            ]);
    }

    public function render(): string
    {
        return <<<'BLADE'
            <div>
                {{ $this->table }}
                <x-filament-actions::modals />
            </div>
            BLADE;
    }
}
