<?php

namespace Noop\Commerce\Filament\Actions;

use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Components\Component;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Noop\Commerce\Commerce;
use Noop\Commerce\Enums\BillingFlow;
use Noop\Commerce\Enums\OrderStatus;
use Noop\Commerce\Enums\PaymentMethod;
use Noop\Commerce\Filament\Actions\Concerns\CallsCommerce;
use Noop\Commerce\Models\Order;
use Noop\Moloni\Enums\DocumentStatus;
use Noop\Moloni\Enums\DocumentType;
use Noop\Moloni\Models\InvoiceDocument;
use Noop\Moloni\Support\Vat;

/**
 * Records a payment received by hand (Commerce::recordPayment()). In circuit B it may also pay
 * other open orders invoiced to the same customer: one payment, one receipt. The key generated when
 * the form opens makes a double submit return the same payment.
 */
class RecordPaymentAction extends Action
{
    use CallsCommerce;

    private const MAX_CANDIDATES = 50;

    public static function getDefaultName(): ?string
    {
        return 'recordPayment';
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCommerceAction();

        $this->label(__('Record payment'))
            ->icon(Heroicon::OutlinedCurrencyEuro)
            ->color('success')
            ->visible(fn (?Order $record): bool => $record?->canReceivePayment() === true)
            ->schema(fn (Order $record): array => self::fields($record))
            ->successNotificationTitle(__('The payment was recorded.'))
            ->action(function (Order $record, array $data): void {
                $allocations = [[$record, (int) self::cents($data['amount'])]];

                foreach ($data['others'] ?? [] as $other) {
                    $allocations[] = [Commerce::orderModel()::query()->whereKey($other['order'])->firstOrFail(), (int) self::cents($other['amount'])];
                }

                $this->callCommerce(fn () => app(Commerce::class)->recordPayment(
                    $allocations,
                    PaymentMethod::from((string) $data['method']),
                    Carbon::parse((string) $data['paid_at']),
                    self::user(),
                    blank($data['notes'] ?? null) ? null : (string) $data['notes'],
                    (string) $data['idempotency_key'],
                ));
            });
    }

    /**
     * @return list<Component>
     */
    private static function fields(Order $record): array
    {
        $candidates = self::jointCandidates($record);

        return [
            // Fixed when the form opens, so a second submit of it is the same request.
            self::amountInput()
                ->required()
                ->default(self::euros(app(Commerce::class)->dueCents($record)))
                ->readOnly($record->billing_flow === BillingFlow::PayFirst),
            Select::make('method')
                ->label(__('Method'))
                ->options(collect(PaymentMethod::cases())->mapWithKeys(fn (PaymentMethod $method) => [$method->value => $method->getLabel()])->all())
                ->default(PaymentMethod::Transfer->value)
                ->required(),
            DateTimePicker::make('paid_at')
                ->label(__('Paid at'))
                ->default(now())
                ->maxDate(now())
                ->required(),
            Textarea::make('notes')
                ->label(__('Notes')),
            Repeater::make('others')
                ->label(__('Other open orders of the same customer, paid with this payment'))
                ->visible($candidates !== [])
                ->defaultItems(0)
                ->schema([
                    Select::make('order')
                        ->label(__('Order'))
                        ->options($candidates)
                        ->required(),
                    self::amountInput()
                        ->required(),
                ])
                ->columns(2),
            Hidden::make('idempotency_key')
                ->default(fn (): string => (string) Str::ulid())
                ->required(),
        ];
    }

    /**
     * Other open circuit B orders with an issued invoice to the same Moloni customer and VAT number
     * as this order's: what one receipt can settle together. None for a final consumer.
     *
     * @return array<int|string, string> Order key => label.
     */
    public static function jointCandidates(Order $order): array
    {
        if ($order->billing_flow !== BillingFlow::InvoiceFirst || Vat::isFinalConsumer($order->billing_vat)) {
            return [];
        }

        $issued = fn () => InvoiceDocument::query()
            ->where('type', DocumentType::Invoice)
            ->where('status', DocumentStatus::Issued)
            ->where('invoiceable_type', $order->getMorphClass());

        $invoice = $issued()->where('invoiceable_id', $order->getKey())->first();

        if ($invoice === null || $invoice->customer_vat === null || $invoice->customer_vat !== Vat::normalize($order->billing_vat)) {
            return [];
        }

        $commerce = app(Commerce::class);
        $ids = $issued()
            ->where('invoiceable_id', '!=', $order->getKey())
            ->where('customer_id', $invoice->customer_id)
            ->where('customer_vat', $invoice->customer_vat)
            ->orderBy('invoiceable_id')
            ->pluck('invoiceable_id');

        return Commerce::orderModel()::query()
            ->whereKey($ids)
            ->whereIn('status', [OrderStatus::PendingPayment, OrderStatus::PartiallyPaid])
            ->where('billing_flow', BillingFlow::InvoiceFirst)
            ->orderBy('id')
            ->get()
            ->map(fn (Order $other): array => [$other, $commerce->dueCents($other)])
            ->filter(fn (array $pair): bool => $pair[1] > 0)
            ->take(self::MAX_CANDIDATES)
            ->mapWithKeys(fn (array $pair): array => [$pair[0]->getKey() => __(':number (due :due)', [
                'number' => $pair[0]->number,
                'due' => self::money($pair[1], $pair[0]->currency),
            ])])
            ->all();
    }

    private static function euros(int $cents): string
    {
        return number_format($cents / 100, 2, '.', '');
    }
}
