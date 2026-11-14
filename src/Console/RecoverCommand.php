<?php

namespace Noop\Commerce\Console;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Noop\Commerce\Billing;
use Noop\Commerce\Commerce;
use Noop\Commerce\Contracts\Payable;
use Noop\Commerce\Enums\OrderStatus;
use Noop\Commerce\Models\Payment;
use Throwable;

/**
 * Requests the documents a process that died between a commit and its after-commit sync left
 * missing: every order and payment touched in the last 24 hours is synced again. A failed
 * document is never requested again here.
 */
class RecoverCommand extends Command
{
    protected $signature = 'commerce:recover';

    protected $description = 'Request the Moloni documents missing for recent orders and payments';

    public function handle(Billing $billing): int
    {
        $since = now()->subDay();
        $failed = 0;
        $sync = function (Model&Payable $payable) use ($billing, &$failed) {
            try {
                $billing->sync($payable);
            } catch (Throwable $e) {
                // One payable must not stop the others.
                report($e);
                $failed++;
            }
        };

        Commerce::orderModel()::query()
            ->whereIn('status', [OrderStatus::PendingPayment, OrderStatus::PartiallyPaid, OrderStatus::Paid])
            ->where('updated_at', '>=', $since)
            ->lazyById()
            ->each($sync);

        Payment::query()
            ->whereNull('voided_at')
            ->where('updated_at', '>=', $since)
            ->with('allocations.payable')
            ->lazyById()
            ->each(function (Payment $payment) use ($sync) {
                foreach ($payment->allocations as $allocation) {
                    if ($allocation->payable instanceof Payable) {
                        $sync($allocation->payable);
                    }
                }
            });

        if ($failed > 0) {
            $this->components->warn("{$failed} payables failed to sync.");
        }

        return self::SUCCESS;
    }
}
