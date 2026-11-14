<?php

namespace Noop\Commerce\Filament\Resources\Orders\Tables;

use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Noop\Commerce\Commerce;
use Noop\Commerce\Enums\BillingFlow;
use Noop\Commerce\Enums\OrderStatus;
use Noop\Commerce\Models\Order;

class OrdersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('number')->label(__('Number'))->searchable(),
                TextColumn::make('billing_name')->label(__('Billing name'))->placeholder('-')->searchable(),
                TextColumn::make('total_cents')->label(__('Total'))
                    ->money(fn (Order $record): string => $record->currency, divideBy: 100),
                TextColumn::make('due')->label(__('Due'))
                    ->state(fn (Order $record): int => app(Commerce::class)->dueCents($record))
                    ->money(fn (Order $record): string => $record->currency, divideBy: 100),
                TextColumn::make('status')->label(__('Status'))->badge()
                    ->formatStateUsing(fn (OrderStatus $state): string => $state->getLabel())
                    ->color(fn (OrderStatus $state): string => $state->getColor()),
                TextColumn::make('billing_flow')->label(__('Billing flow'))
                    ->formatStateUsing(fn (BillingFlow $state): string => $state->getLabel()),
                TextColumn::make('created_at')->label(__('Created at'))->dateTime(),
            ])
            ->filters([
                SelectFilter::make('status')->label(__('Status'))
                    ->options(collect(OrderStatus::cases())->mapWithKeys(fn (OrderStatus $status): array => [$status->value => $status->getLabel()])->all()),
            ])
            ->recordActions([
                ViewAction::make(),
            ]);
    }
}
