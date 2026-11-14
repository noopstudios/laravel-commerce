<?php

namespace Noop\Commerce;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Noop\Commerce\Contracts\Payable;
use Noop\Commerce\Enums\BillingFlow;
use Noop\Commerce\Enums\OrderStatus;
use Noop\Commerce\Models\Order;
use Noop\Commerce\Models\Payment;
use Noop\Commerce\Models\PaymentAllocation;
use Noop\Moloni\Contracts\Invoiceable;
use Noop\Moloni\Data\PaymentData;
use Noop\Moloni\Enums\DocumentStatus;
use Noop\Moloni\Enums\DocumentType;
use Noop\Moloni\Events\DocumentIssued;
use Noop\Moloni\Facades\Moloni;
use Noop\Moloni\Models\InvoiceDocument;

/**
 * Requests the Moloni documents a payable is missing. Paying never depends on invoicing: nothing
 * here runs inside a payment's transaction, and no exception from it reaches the payment's caller.
 * The automatic paths never request a failed document again; the panel does, with $retryFailed.
 */
class Billing
{
    public function __construct(private readonly Commerce $commerce) {}

    /**
     * Syncs the payable once the current transaction commits (at once outside one); an exception
     * is reported, never thrown.
     */
    public static function syncAfterCommit(Model&Payable $payable): void
    {
        rescue(fn () => $payable->getConnection()->afterCommit(fn () => rescue(fn () => app(self::class)->sync($payable))));
    }

    /**
     * Listener of DocumentIssued: an issued invoice lets the payments recorded before it get their
     * receipt. Runs inside Moloni's issuing job, so it never throws.
     */
    public static function invoiceIssued(DocumentIssued $event): void
    {
        rescue(function () use ($event) {
            $payable = $event->document->type === DocumentType::Invoice ? $event->document->invoiceable : null;

            if ($payable instanceof Payable) {
                self::syncAfterCommit($payable);
            }
        });
    }

    /**
     * Requests whatever document is missing for the payable. Safe to call any number of times.
     * With $retryFailed (the operator's action), a failed document counts as missing: a new invoice
     * or invoice-receipt is requested from the payable's current data, a failed receipt is sent
     * again, and a receipt cancelled in Moloni is replaced by a new one. Without it, any receipt of
     * a payment, cancelled included, counts. Exceptions propagate.
     */
    public function sync(Model&Payable $payable, bool $retryFailed = false): void
    {
        $payable = $payable->fresh();

        if (! $payable instanceof Payable || ! $payable instanceof Invoiceable || $payable->totalCents() <= 0) {
            return;
        }

        if ($payable->billingFlow() === BillingFlow::PayFirst) {
            $this->syncInvoiceReceipt($payable, $retryFailed);

            return;
        }

        if ($payable instanceof Order) {
            $this->syncInvoice($payable, $retryFailed);
        }

        $this->syncReceipts($payable, $retryFailed);
    }

    /**
     * Circuit B, the package's order only: a confirmed order without any invoice gets one.
     */
    private function syncInvoice(Order $order, bool $retryFailed): void
    {
        $confirmed = in_array($order->status, [OrderStatus::PendingPayment, OrderStatus::PartiallyPaid, OrderStatus::Paid], true);

        if ($confirmed && ! $this->hasInvoice($order, $retryFailed)) {
            Moloni::invoice($order, $order->payment_terms_days);
        }
    }

    /**
     * Circuit A: a paid payable without an invoice gets an invoice-receipt for its payment.
     */
    private function syncInvoiceReceipt(Model&Payable&Invoiceable $payable, bool $retryFailed): void
    {
        if ($this->commerce->dueCents($payable) !== 0 || $this->hasInvoice($payable, true)) {
            return;
        }

        // Circuit A takes one payment of the whole due.
        $allocation = PaymentAllocation::query()
            ->where('payable_type', $payable->getMorphClass())
            ->where('payable_id', $payable->getKey())
            ->whereHas('payment', fn ($payment) => $payment->whereNull('voided_at'))
            ->sole();

        if ($this->hasInvoiceReceipt($payable, $allocation, $retryFailed)) {
            return;
        }

        $this->whileLocked($allocation->payment_id, function (Payment $payment) use ($payable, $allocation, $retryFailed) {
            if (! $this->hasInvoiceReceipt($payable, $allocation, $retryFailed)) {
                Moloni::invoiceReceipt($payable, new PaymentData($payment->method->value, $payment->paid_at, $allocation->amount_cents));
            }
        });
    }

    /**
     * A live invoice or invoice-receipt or, unless retrying failed documents, one in any status
     * created since the current payment was allocated (a failed one waits for the panel). One left
     * by an earlier, voided payment does not count. Same-second rows count: timestamps have
     * one-second precision.
     */
    private function hasInvoiceReceipt(Model $payable, PaymentAllocation $allocation, bool $retryFailed): bool
    {
        return InvoiceDocument::query()
            ->whereIn('type', [DocumentType::Invoice, DocumentType::InvoiceReceipt])
            ->where('invoiceable_type', $payable->getMorphClass())
            ->where('invoiceable_id', $payable->getKey())
            ->where(fn ($query) => $query
                ->whereIn('status', DocumentStatus::live())
                ->when(! $retryFailed, fn ($query) => $query->orWhere('created_at', '>=', $allocation->created_at)))
            ->exists();
    }

    /**
     * Circuit B: each payment to the payable gets one receipt, once every invoice it pays is issued.
     */
    private function syncReceipts(Model&Payable $payable, bool $retryFailed): void
    {
        $payments = Payment::query()
            ->whereNull('voided_at')
            ->whereHas('allocations', fn ($allocation) => $allocation
                ->where('payable_type', $payable->getMorphClass())
                ->where('payable_id', $payable->getKey()))
            ->orderBy('id')
            ->get();

        foreach ($payments as $payment) {
            if ($this->hasReceipt($payment, $retryFailed) || ($invoices = $this->issuedInvoices($payment)) === null) {
                continue;
            }

            $this->whileLocked($payment->id, function (Payment $payment) use ($invoices, $retryFailed) {
                if (! $this->hasReceipt($payment, $retryFailed)) {
                    $cents = array_sum(array_column($invoices, 1));
                    Moloni::receipt($payment, $invoices, new PaymentData($payment->method->value, $payment->paid_at, $cents), $this->receiptKey($payment));
                }
            });
        }
    }

    /**
     * The same key returns a failed receipt and sends it again. Each receipt cancelled in Moloni
     * moves the key on, so a replacement is a new receipt. Call under the payment's lock: the count
     * is stable there while the payment has no live receipt.
     */
    private function receiptKey(Payment $payment): string
    {
        $voided = InvoiceDocument::query()
            ->where('type', DocumentType::Receipt)
            ->where('invoiceable_type', $payment->getMorphClass())
            ->where('invoiceable_id', $payment->getKey())
            ->where('status', DocumentStatus::Voided)
            ->count();

        return $voided === 0 ? "payment-{$payment->id}" : "payment-{$payment->id}-{$voided}";
    }

    /**
     * The payment's allocations as receipt allocations, summed per invoice, or null while any
     * payable it paid has no issued invoice.
     *
     * @return list<array{0: InvoiceDocument, 1: int}>|null
     */
    private function issuedInvoices(Payment $payment): ?array
    {
        $invoices = [];

        foreach ($payment->allocations()->orderBy('id')->get() as $allocation) {
            // The payable's live invoice or invoice-receipt (the one that holds its active_key).
            $invoice = InvoiceDocument::query()
                ->whereIn('type', [DocumentType::Invoice, DocumentType::InvoiceReceipt])
                ->whereIn('status', DocumentStatus::live())
                ->where('invoiceable_type', $allocation->payable_type)
                ->where('invoiceable_id', $allocation->payable_id)
                ->first();

            if ($invoice === null || $invoice->type !== DocumentType::Invoice || $invoice->status !== DocumentStatus::Issued) {
                return null;
            }

            $invoices[$invoice->id] ??= [$invoice, 0];
            $invoices[$invoice->id][1] += $allocation->amount_cents;
        }

        return array_values($invoices);
    }

    /**
     * Requests a document for a payment while its row is locked, so it cannot be voided meanwhile:
     * voidPayment() takes the same lock before it looks for documents. One document per transaction,
     * kept short; Moloni only records a pending row here and queues its job after the commit.
     *
     * @param  Closure(Payment): void  $request
     */
    private function whileLocked(int $paymentId, Closure $request): void
    {
        $connection = (new Payment)->getConnection();

        $connection->transaction(function () use ($paymentId, $request) {
            $payment = Payment::query()->whereKey($paymentId)->lockForUpdate()->firstOrFail();

            if ($payment->voided_at === null) {
                $request($payment);
            }
        }, $connection->transactionLevel() === 0 ? 3 : 1);
    }

    /**
     * Any invoice or invoice-receipt, in any status (a failed one waits for the panel), or only a
     * live one when retrying failed documents.
     */
    private function hasInvoice(Model $payable, bool $liveOnly): bool
    {
        return $this->documentExists([DocumentType::Invoice, DocumentType::InvoiceReceipt], $payable, $liveOnly);
    }

    /**
     * Any receipt of the payment, in any status (a failed one waits for the panel), or only a live
     * one when retrying failed documents.
     */
    private function hasReceipt(Payment $payment, bool $liveOnly): bool
    {
        return $this->documentExists([DocumentType::Receipt], $payment, $liveOnly);
    }

    /**
     * @param  list<DocumentType>  $types
     */
    private function documentExists(array $types, Model $invoiceable, bool $liveOnly): bool
    {
        return InvoiceDocument::query()
            ->whereIn('type', $types)
            ->where('invoiceable_type', $invoiceable->getMorphClass())
            ->where('invoiceable_id', $invoiceable->getKey())
            ->when($liveOnly, fn ($query) => $query->whereIn('status', DocumentStatus::live()))
            ->exists();
    }
}
