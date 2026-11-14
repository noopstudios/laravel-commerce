<?php

namespace Noop\Commerce\Console;

use Closure;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Noop\Commerce\Billing;
use Noop\Commerce\Commerce;
use Noop\Commerce\Contracts\Payable;
use Noop\Commerce\Enums\OrderStatus;
use Noop\Commerce\Models\PaymentAllocation;
use Throwable;

/**
 * Requests the documents a process that died between a commit and its after-commit sync left
 * missing: every order touched and every payable allocated a payment in the last 24 hours is
 * synced again, once per run. A failed document is never requested again here.
 */
class RecoverCommand extends Command
{
    protected $signature = 'commerce:recover';

    protected $description = 'Request the Moloni documents missing for recent orders and payments';

    public function handle(Billing $billing): int
    {
        $since = now()->subDay();
        $seen = [];
        $failed = 0;

        $sync = function (string $type, int|string $id, Closure $resolve) use ($billing, &$seen, &$failed) {
            if (isset($seen["{$type}:{$id}"])) {
                return;
            }

            $seen["{$type}:{$id}"] = true;

            try {
                $payable = $resolve();

                if ($payable instanceof Model && $payable instanceof Payable) {
                    $billing->sync($payable);
                }
            } catch (Throwable $e) {
                // One payable must not stop the others. Only the first is reported: a request
                // refused before any document exists would otherwise be reported every run.
                if ($failed === 0) {
                    report($e);
                }

                $failed++;
            }
        };

        Commerce::orderModel()::query()
            ->whereIn('status', [OrderStatus::PendingPayment, OrderStatus::PartiallyPaid, OrderStatus::Paid])
            ->where('updated_at', '>=', $since)
            ->lazyById()
            ->each(fn (Model $order) => $sync($order->getMorphClass(), $order->getKey(), fn () => $order));

        // A payment under review allocated now touches neither the payment nor the payable.
        PaymentAllocation::query()
            ->where('created_at', '>=', $since)
            ->whereHas('payment', fn ($payment) => $payment->whereNull('voided_at'))
            ->lazyById()
            ->each(fn (PaymentAllocation $allocation) => $sync($allocation->payable_type, $allocation->payable_id, fn () => $allocation->payable));

        if ($failed > 0) {
            $this->components->warn("{$failed} payables failed to sync; the first was reported.");
        }

        return self::SUCCESS;
    }
}
