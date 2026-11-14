<?php

namespace Noop\Commerce\Eupago;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Noop\Commerce\Commerce;
use Noop\Commerce\Contracts\Payable;
use Noop\Commerce\Enums\BillingFlow;
use Noop\Commerce\Exceptions\CommerceException;
use Noop\Eupago\Contracts\HandlesEupagoPayments;
use Noop\Eupago\Enums\FailureType;
use Noop\Eupago\Exceptions\EupagoException;
use Noop\Eupago\Facades\Eupago;
use Noop\Eupago\Models\PaymentAttempt;
use SensitiveParameter;
use Throwable;

/**
 * Everything outside src/Eupago reaches laravel-eupago through here. It implements nothing from that
 * package, so it loads when the package is not installed.
 */
class EupagoBridge
{
    public function __construct(private readonly Commerce $commerce) {}

    /**
     * Binds the commerce handler when laravel-eupago is installed and nothing is bound yet; a host
     * that binds its own later (in its AppServiceProvider) replaces it.
     */
    public static function register(Application $app): void
    {
        if (interface_exists(HandlesEupagoPayments::class)) {
            $app->bindIf(HandlesEupagoPayments::class, EupagoPayments::class);
        }
    }

    public static function installed(): bool
    {
        return class_exists(Eupago::class);
    }

    /**
     * The payable's EuPago payment attempts, oldest first, as plain values; none when laravel-eupago
     * is not installed.
     *
     * @return Collection<int, array{method: string, amount_cents: int, entity: string|null, reference: string|null, status: string, expires_at: Carbon, paid_at: Carbon|null, error_message: string|null}>
     */
    public function attempts(Model&Payable $payable): Collection
    {
        if (! self::installed()) {
            return collect();
        }

        return PaymentAttempt::query()
            ->where('payable_type', $payable->getMorphClass())
            ->where('payable_id', $payable->getKey())
            ->orderBy('id')
            ->get()
            ->map(fn (PaymentAttempt $attempt) => [
                'method' => $attempt->method->value,
                'amount_cents' => $attempt->amount_cents,
                'entity' => $attempt->entity,
                'reference' => $attempt->reference,
                'status' => $attempt->status->value,
                'expires_at' => $attempt->expires_at,
                'paid_at' => $attempt->paid_at,
                'error_message' => $attempt->error_message,
            ]);
    }

    /**
     * What to tell the operator about a failed payment request, or null when the exception is not
     * an EuPago failure.
     */
    public function failureMessage(Throwable $e): ?string
    {
        if (! $e instanceof EupagoException) {
            return null;
        }

        return match ($e->type) {
            FailureType::Rejected => __('EuPago refused the request: :reason You may try again.', ['reason' => $e->getMessage()]),
            FailureType::Unknown => __('EuPago did not confirm the request, so a payable reference may exist. Do not try again: the order stays blocked until the payment notice, the expiry, or an operator resolves the attempt in the EuPago panel.'),
            FailureType::Blocked => __('The request was not sent: another request is in progress, an earlier one is unresolved, or the attempts limit was reached. :reason', ['reason' => $e->getMessage()]),
        };
    }

    public function multibanco(Model&Payable $payable, ?int $amountCents): PaymentAttempt
    {
        // Computed first: the static call resolves Eupago before its arguments, skipping the install check.
        $amount = $this->amount($payable, $amountCents);

        return Eupago::multibanco($payable, $amount);
    }

    public function mbway(Model&Payable $payable, #[SensitiveParameter] string $phone, ?int $amountCents): PaymentAttempt
    {
        $amount = $this->amount($payable, $amountCents);

        return Eupago::mbway($payable, $amount, $phone);
    }

    /**
     * The amount to request: the due by default, all of it in circuit A, at most all of it in circuit B.
     */
    private function amount(Model&Payable $payable, ?int $amountCents): int
    {
        if (! self::installed()) {
            throw new CommerceException('Install noopstudios/laravel-eupago to request Multibanco and MB WAY payments.');
        }

        $label = $payable->paymentLabel();

        if ($payable->currency() !== 'EUR') {
            throw new CommerceException("{$label} is in {$payable->currency()}; EuPago only charges EUR.");
        }

        if (! $payable->canReceivePayment()) {
            throw new CommerceException("{$label} cannot receive a payment now.");
        }

        $dueCents = $this->commerce->dueCents($payable);
        $amountCents ??= $dueCents;

        if ($payable->billingFlow() === BillingFlow::PayFirst && $amountCents !== $dueCents) {
            throw new CommerceException("{$label} must be paid in full: {$dueCents} cents.");
        }

        if ($amountCents <= 0 || $amountCents > $dueCents) {
            throw new CommerceException("The amount of {$amountCents} cents for {$label} must be greater than zero and at most the {$dueCents} it owes.");
        }

        return $amountCents;
    }
}
