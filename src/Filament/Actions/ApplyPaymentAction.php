<?php

namespace Noop\Commerce\Filament\Actions;

use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Support\Icons\Heroicon;
use Noop\Commerce\Commerce;
use Noop\Commerce\Enums\OrderStatus;
use Noop\Commerce\Filament\Actions\Concerns\CallsCommerce;
use Noop\Commerce\Models\Payment;

/**
 * Applies a payment under review to one order, once (Commerce::allocate()): the order is reopened
 * if it expired or was cancelled.
 */
class ApplyPaymentAction extends Action
{
    use CallsCommerce;

    public static function getDefaultName(): ?string
    {
        return 'applyToOrder';
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCommerceAction();

        $orders = fn () => Commerce::orderModel()::query()->where('status', '!=', OrderStatus::Draft);

        $this->label(__('Apply to order'))
            ->icon(Heroicon::OutlinedArrowRightCircle)
            ->color('success')
            ->visible(fn (?Payment $record): bool => $record !== null
                && $record->needs_review
                && $record->voided_at === null
                && $record->allocations->isEmpty())
            ->schema(fn (Payment $record): array => [
                Select::make('order')
                    ->label(__('Order'))
                    ->searchable()
                    ->getSearchResultsUsing(fn (string $search): array => $orders()->where('number', 'like', "%{$search}%")->orderByDesc('id')->limit(50)->pluck('number', 'id')->all())
                    ->getOptionLabelUsing(fn (int|string $value): ?string => $orders()->whereKey($value)->value('number'))
                    ->default($record->payable_type === (new (Commerce::orderModel()))->getMorphClass() ? $record->payable_id : null)
                    ->required(),
                self::amountInput()
                    ->helperText(__('Leave empty to apply as much of the payment as the order owes.')),
            ])
            ->successNotificationTitle(__('The payment was applied to the order.'))
            ->action(function (Payment $record, array $data) use ($orders): void {
                $order = $orders()->whereKey($data['order'])->firstOrFail();

                $this->callCommerce(fn () => app(Commerce::class)->allocate($record, $order, self::cents($data['amount'] ?? null)));
            });
    }
}
