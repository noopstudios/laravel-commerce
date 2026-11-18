<?php

namespace Noop\Commerce\Filament\Resources\Payments\Tables;

use Filament\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Noop\Commerce\Enums\PaymentMethod;
use Noop\Commerce\Enums\PaymentSource;
use Noop\Commerce\Filament\Actions\ApplyPaymentAction;
use Noop\Commerce\Filament\Actions\ResolveReviewAction;
use Noop\Commerce\Filament\Actions\VoidPaymentAction;
use Noop\Commerce\Filament\Resources\Payments\PaymentResource;
use Noop\Commerce\Models\Payment;

class PaymentsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('payable'))
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('id')->label(__('Id')),
                TextColumn::make('source')->label(__('Source'))
                    ->formatStateUsing(fn (PaymentSource $state): string => $state->getLabel()),
                TextColumn::make('method')->label(__('Method'))
                    ->formatStateUsing(fn (PaymentMethod $state): string => $state->getLabel()),
                TextColumn::make('amount_cents')->label(__('Amount'))
                    ->money(fn (Payment $record): string => $record->currency, divideBy: 100),
                TextColumn::make('allocated')->label(__('Allocated'))
                    ->state(fn (Payment $record): int => PaymentResource::allocatedCents($record))
                    ->money(fn (Payment $record): string => $record->currency, divideBy: 100),
                TextColumn::make('remaining')->label(__('Remaining'))
                    ->state(fn (Payment $record): int => $record->amount_cents - PaymentResource::allocatedCents($record))
                    ->money(fn (Payment $record): string => $record->currency, divideBy: 100),
                TextColumn::make('paid_at')->label(__('Paid at'))->dateTime(),
                TextColumn::make('payable')->label(__('Arrived for'))
                    ->state(fn (Payment $record): ?string => PaymentResource::payableLabel($record->payable))
                    ->placeholder('-'),
                IconColumn::make('needs_review')->label(__('Under review'))->boolean(),
                TextColumn::make('review_reason')->label(__('Review reason'))
                    ->formatStateUsing(fn (?string $state): ?string => PaymentResource::reviewReasonLabel($state))->placeholder('-'),
                TextColumn::make('voided_at')->label(__('Voided at'))->dateTime()->placeholder('-'),
            ])
            ->filters([
                TernaryFilter::make('needs_review')->label(__('Under review'))->default(true),
            ])
            ->recordActions([
                ViewAction::make(),
                ApplyPaymentAction::make(),
                ResolveReviewAction::make(),
                VoidPaymentAction::make(),
            ]);
    }
}
