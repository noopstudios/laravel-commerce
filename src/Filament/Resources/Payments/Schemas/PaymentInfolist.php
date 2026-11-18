<?php

namespace Noop\Commerce\Filament\Resources\Payments\Schemas;

use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Noop\Commerce\Enums\PaymentMethod;
use Noop\Commerce\Enums\PaymentSource;
use Noop\Commerce\Filament\Resources\Payments\PaymentResource;
use Noop\Commerce\Models\Payment;
use Noop\Commerce\Models\PaymentAllocation;

class PaymentInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Section::make(__('Payment'))
                ->columns(3)
                ->schema([
                    TextEntry::make('source')->label(__('Source'))
                        ->formatStateUsing(fn (PaymentSource $state): string => $state->getLabel()),
                    TextEntry::make('method')->label(__('Method'))
                        ->formatStateUsing(fn (PaymentMethod $state): string => $state->getLabel()),
                    TextEntry::make('external_id')->label(__('EuPago transaction'))->placeholder('-'),
                    TextEntry::make('amount_cents')->label(__('Amount'))
                        ->money(fn (Payment $record): string => $record->currency, divideBy: 100),
                    TextEntry::make('allocated')->label(__('Allocated'))
                        ->state(fn (Payment $record): int => PaymentResource::allocatedCents($record))
                        ->money(fn (Payment $record): string => $record->currency, divideBy: 100),
                    TextEntry::make('remaining')->label(__('Remaining'))
                        ->state(fn (Payment $record): int => $record->amount_cents - PaymentResource::allocatedCents($record))
                        ->money(fn (Payment $record): string => $record->currency, divideBy: 100),
                    TextEntry::make('paid_at')->label(__('Paid at'))->dateTime(),
                    TextEntry::make('payable')->label(__('Arrived for'))
                        ->state(fn (Payment $record): ?string => PaymentResource::payableLabel($record->payable))
                        ->placeholder('-'),
                    TextEntry::make('recorded_by_id')->label(__('Recorded by (user id)'))->placeholder('-'),
                    TextEntry::make('notes')->label(__('Notes'))->placeholder('-')->columnSpanFull(),
                ]),
            Section::make(__('Review'))
                ->columns(3)
                ->schema([
                    IconEntry::make('needs_review')->label(__('Under review'))->boolean(),
                    TextEntry::make('review_reason')->label(__('Review reason'))
                        ->formatStateUsing(fn (?string $state): ?string => PaymentResource::reviewReasonLabel($state))->placeholder('-'),
                ]),
            Section::make(__('Void'))
                ->columns(3)
                ->visible(fn (Payment $record): bool => $record->voided_at !== null)
                ->schema([
                    TextEntry::make('voided_at')->label(__('Voided at'))->dateTime(),
                    TextEntry::make('voided_by_id')->label(__('Voided by (user id)'))->placeholder('-'),
                    TextEntry::make('void_reason')->label(__('Reason'))->placeholder('-'),
                ]),
            Section::make(__('Allocations'))
                ->schema([
                    RepeatableEntry::make('allocations')
                        ->hiddenLabel()
                        ->placeholder(__('Not allocated.'))
                        ->columns(2)
                        ->schema([
                            TextEntry::make('payable')->label(__('Paid'))
                                ->state(fn (PaymentAllocation $record): ?string => PaymentResource::payableLabel($record->payable)),
                            TextEntry::make('amount_cents')->label(__('Amount'))->money('EUR', divideBy: 100),
                        ]),
                ]),
        ]);
    }
}
