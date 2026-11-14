<?php

namespace Noop\Commerce\Filament\Actions;

use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Support\Icons\Heroicon;
use Noop\Commerce\Commerce;
use Noop\Commerce\Filament\Actions\Concerns\CallsCommerce;
use Noop\Commerce\Models\Payment;

/**
 * Takes a payment out of review without applying it (Commerce::resolveReview()): refunded, or
 * handled elsewhere.
 */
class ResolveReviewAction extends Action
{
    use CallsCommerce;

    public static function getDefaultName(): ?string
    {
        return 'resolveReview';
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCommerceAction();

        $this->label(__('Mark as handled'))
            ->icon(Heroicon::OutlinedCheckCircle)
            ->color('gray')
            ->visible(fn (?Payment $record): bool => $record?->needs_review === true)
            ->schema([
                Textarea::make('note')
                    ->label(__('How it was handled'))
                    ->required()
                    ->maxLength(1000),
            ])
            ->successNotificationTitle(__('The payment left the review queue.'))
            ->action(fn (Payment $record, array $data) => $this->callCommerce(fn () => app(Commerce::class)->resolveReview($record, (string) $data['note'])));
    }
}
