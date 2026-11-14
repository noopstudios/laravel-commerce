<?php

namespace Noop\Commerce\Filament\Actions\Concerns;

use Closure;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Support\Exceptions\Cancel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Number;
use InvalidArgumentException;
use LogicException;
use Noop\Commerce\Eupago\EupagoBridge;
use Noop\Commerce\Exceptions\CommerceException;
use Noop\Moloni\Exceptions\MoloniException;
use Throwable;

/**
 * What every commerce action shares.
 */
trait CallsCommerce
{
    /**
     * The ledger, Moloni and EuPago run their own transactions, and a rollback around an EuPago
     * request could erase the attempt that prevents a second reference: the action never opens one,
     * even on a panel with databaseTransactions(). Only users who manage orders see it.
     */
    protected function setUpCommerceAction(): void
    {
        $this->databaseTransaction(false);
        $this->authorize('manage');
    }

    /**
     * Runs a commerce call. What the ledger, Moloni or EuPago refuses is shown as a danger
     * notification, and the action stops there.
     *
     * @template T
     *
     * @param  Closure(): T  $call
     * @return T
     */
    protected function callCommerce(Closure $call): mixed
    {
        try {
            return $call();
        } catch (CommerceException|MoloniException|InvalidArgumentException|LogicException $e) {
            $message = $e->getMessage();
        } catch (Throwable $e) {
            $message = app(EupagoBridge::class)->failureMessage($e) ?? throw $e;
        }

        Notification::make()->danger()->title($message)->send();

        throw new Cancel;
    }

    /**
     * Cents from an amount typed in euros, or null when left empty.
     */
    protected static function cents(mixed $amount): ?int
    {
        return blank($amount) ? null : (int) round((float) $amount * 100);
    }

    protected static function money(int $cents, string $currency = 'EUR'): string
    {
        return (string) Number::currency($cents / 100, $currency);
    }

    protected static function user(): ?Model
    {
        $user = Filament::auth()->user();

        return $user instanceof Model ? $user : null;
    }
}
