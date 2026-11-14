<?php

namespace Noop\Commerce\Eupago;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Eloquent\Model;
use Noop\Commerce\Commerce;
use Noop\Commerce\Contracts\Payable;
use Noop\Commerce\Enums\BillingFlow;
use Noop\Commerce\Exceptions\CommerceException;
use Noop\Eupago\Contracts\HandlesEupagoPayments;
use Noop\Eupago\Facades\Eupago;
use Noop\Eupago\Models\PaymentAttempt;
use SensitiveParameter;

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

    public function multibanco(Model&Payable $payable, ?int $amountCents): PaymentAttempt
    {
        return Eupago::multibanco($payable, $this->amount($payable, $amountCents));
    }

    public function mbway(Model&Payable $payable, #[SensitiveParameter] string $phone, ?int $amountCents): PaymentAttempt
    {
        return Eupago::mbway($payable, $this->amount($payable, $amountCents), $phone);
    }

    /**
     * The amount to request: the due by default, all of it in circuit A, at most all of it in circuit B.
     */
    private function amount(Model&Payable $payable, ?int $amountCents): int
    {
        if (! class_exists(Eupago::class)) {
            throw new CommerceException('Install noopstudios/laravel-eupago to request Multibanco and MB WAY payments.');
        }

        $label = $payable->paymentLabel();

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
