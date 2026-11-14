<?php

namespace Noop\Commerce\Filament\Resources\Orders\Schemas;

use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Noop\Commerce\Commerce;
use Noop\Commerce\Enums\BillingFlow;
use Noop\Commerce\Enums\OrderStatus;
use Noop\Commerce\Eupago\EupagoBridge;
use Noop\Commerce\Filament\Resources\Orders\OrderDocuments;
use Noop\Commerce\Filament\Resources\Orders\OrderResource;
use Noop\Commerce\Models\Order;
use Noop\Commerce\Models\OrderItem;
use Noop\Commerce\Models\Payment;
use Noop\Commerce\Models\PaymentAllocation;

class OrderInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Section::make(__('Order'))
                ->columns(3)
                ->schema([
                    TextEntry::make('number')->label(__('Number')),
                    TextEntry::make('status')->label(__('Status'))->badge()
                        ->formatStateUsing(fn (OrderStatus $state): string => $state->getLabel())
                        ->color(fn (OrderStatus $state): string => $state->getColor()),
                    TextEntry::make('documents')->label(__('Documents'))->badge()->color('danger')
                        ->state(__('Document missing'))
                        ->helperText(__('Use "Issue documents" to request it.'))
                        ->visible(fn (Order $record): bool => OrderResource::documentMissing($record)),
                    TextEntry::make('billing_flow')->label(__('Billing flow'))
                        ->formatStateUsing(fn (BillingFlow $state): string => $state->getLabel()),
                    TextEntry::make('subtotal_cents')->label(__('Subtotal'))
                        ->money(fn (Order $record): string => $record->currency, divideBy: 100),
                    TextEntry::make('tax_cents')->label(__('VAT'))
                        ->money(fn (Order $record): string => $record->currency, divideBy: 100),
                    TextEntry::make('total_cents')->label(__('Total'))
                        ->money(fn (Order $record): string => $record->currency, divideBy: 100),
                    TextEntry::make('due')->label(__('Due'))
                        ->state(fn (Order $record): int => app(Commerce::class)->dueCents($record))
                        ->money(fn (Order $record): string => $record->currency, divideBy: 100),
                    TextEntry::make('payment_terms_days')->label(__('Payment terms (days)')),
                    TextEntry::make('paid_at')->label(__('Paid at'))->dateTime()->placeholder('-'),
                    TextEntry::make('created_at')->label(__('Created at'))->dateTime(),
                    TextEntry::make('customer_reference')->label(__('Customer reference'))->placeholder('-'),
                    TextEntry::make('notes')->label(__('Notes'))->placeholder('-')->columnSpanFull(),
                ]),
            Section::make(__('Billing data'))
                ->columns(3)
                ->schema([
                    TextEntry::make('billing_name')->label(__('Name'))->placeholder('-'),
                    TextEntry::make('billing_vat')->label(__('VAT number'))->placeholder('-'),
                    IconEntry::make('is_business')->label(__('Business'))->boolean(),
                    TextEntry::make('billing_email')->label(__('Email'))->placeholder('-'),
                    TextEntry::make('billing_phone')->label(__('Phone'))->placeholder('-'),
                    TextEntry::make('billing_address')->label(__('Address'))->placeholder('-'),
                    TextEntry::make('billing_postal_code')->label(__('Postal code'))->placeholder('-'),
                    TextEntry::make('billing_city')->label(__('City'))->placeholder('-'),
                    TextEntry::make('billing_country')->label(__('Country'))->placeholder('-'),
                ]),
            Section::make(__('Lines'))
                ->schema([
                    RepeatableEntry::make('items')
                        ->hiddenLabel()
                        ->table([
                            RepeatableEntry\TableColumn::make(__('Name')),
                            RepeatableEntry\TableColumn::make(__('Reference')),
                            RepeatableEntry\TableColumn::make(__('Quantity')),
                            RepeatableEntry\TableColumn::make(__('Unit price')),
                            RepeatableEntry\TableColumn::make(__('VAT rate')),
                            RepeatableEntry\TableColumn::make(__('Total')),
                        ])
                        ->schema([
                            TextEntry::make('name'),
                            TextEntry::make('reference')->placeholder('-'),
                            TextEntry::make('quantity'),
                            TextEntry::make('unit_net_price'),
                            TextEntry::make('tax_rate')->suffix('%'),
                            TextEntry::make('total_cents')->money(fn (OrderItem $record): string => $record->order->currency ?? 'EUR', divideBy: 100),
                        ]),
                ]),
            Section::make(__('Payments'))
                ->schema([
                    RepeatableEntry::make('payments')
                        ->hiddenLabel()
                        ->placeholder(__('No payments.'))
                        ->state(fn (Order $record): array => self::payments($record))
                        ->columns(4)
                        ->schema([
                            TextEntry::make('paid_at')->label(__('Paid at'))->dateTime(),
                            TextEntry::make('source')->label(__('Source')),
                            TextEntry::make('method')->label(__('Method')),
                            TextEntry::make('state')->label(__('State'))->badge()
                                ->color(fn (string $state): string => match ($state) {
                                    __('Voided') => 'gray',
                                    __('Under review') => 'warning',
                                    default => 'success',
                                }),
                            TextEntry::make('amount_cents')->label(__('Payment amount'))->money(fn (Order $record): string => $record->currency, divideBy: 100),
                            TextEntry::make('allocated_cents')->label(__('Applied to this order'))->money(fn (Order $record): string => $record->currency, divideBy: 100),
                            TextEntry::make('detail')->label(__('Detail'))->placeholder('-')->columnSpan(2),
                        ]),
                ]),
            Section::make(__('EuPago payment attempts'))
                ->visible(EupagoBridge::installed())
                ->schema([
                    RepeatableEntry::make('eupago_attempts')
                        ->hiddenLabel()
                        ->placeholder(__('No payment attempts.'))
                        ->state(fn (Order $record): array => app(EupagoBridge::class)->attempts($record)->map(fn (array $attempt): array => [
                            ...$attempt,
                            'method' => $attempt['method'] === 'mbway' ? 'MB WAY' : 'Multibanco',
                            'status' => self::attemptStatusLabel($attempt['status']),
                        ])->all())
                        ->columns(4)
                        ->schema([
                            TextEntry::make('method')->label(__('Method')),
                            TextEntry::make('amount_cents')->label(__('Amount'))->money('EUR', divideBy: 100),
                            TextEntry::make('status')->label(__('Status'))->badge(),
                            TextEntry::make('expires_at')->label(__('Expires at'))->dateTime(),
                            TextEntry::make('entity')->label(__('Entity'))->placeholder('-'),
                            TextEntry::make('reference')->label(__('Reference'))->placeholder('-'),
                            TextEntry::make('paid_at')->label(__('Paid at'))->dateTime()->placeholder('-'),
                            TextEntry::make('error_message')->label(__('Error'))->placeholder('-'),
                        ]),
                ]),
            Section::make(__('Moloni documents'))
                ->schema([
                    EmbeddedTable::make(OrderDocuments::class, fn (Order $record): array => ['order' => $record]),
                ]),
        ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function payments(Order $order): array
    {
        return OrderResource::payments($order)->map(function (Payment $payment) use ($order): array {
            [$state, $detail] = match (true) {
                $payment->voided_at !== null => [__('Voided'), __('Voided at :date: :reason', ['date' => $payment->voided_at->format('Y-m-d H:i'), 'reason' => $payment->void_reason])],
                $payment->needs_review => [__('Under review'), $payment->review_reason],
                default => [__('Applied'), $payment->notes],
            };

            return [
                'paid_at' => $payment->paid_at,
                'source' => $payment->source->getLabel(),
                'method' => $payment->method->getLabel(),
                'state' => $state,
                'amount_cents' => $payment->amount_cents,
                'allocated_cents' => $payment->allocations
                    ->filter(fn (PaymentAllocation $allocation) => $allocation->payable_type === $order->getMorphClass() && $allocation->payable_id == $order->getKey())
                    ->sum('amount_cents'),
                'detail' => $detail,
            ];
        })->values()->all();
    }

    private static function attemptStatusLabel(string $status): string
    {
        return match ($status) {
            'creating' => __('Creating'),
            'pending' => __('Awaiting payment'),
            'paid' => __('Paid'),
            'expired' => __('Expired'),
            'failed' => __('Failed'),
            default => __('Unknown'),
        };
    }
}
