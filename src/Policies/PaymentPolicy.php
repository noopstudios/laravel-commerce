<?php

namespace Noop\Commerce\Policies;

use Illuminate\Contracts\Auth\Authenticatable;
use Noop\Commerce\Models\Payment;
use Noop\Commerce\Policies\Concerns\ChecksPermission;

/**
 * Payments are only read in the panel: they are recorded through the order actions and EuPago, and
 * change only through the actions, which need `manage`.
 */
class PaymentPolicy
{
    use ChecksPermission;

    public function viewAny(Authenticatable $user): bool
    {
        return self::sees($user);
    }

    public function view(Authenticatable $user, Payment $payment): bool
    {
        return self::sees($user);
    }

    public function manage(Authenticatable $user, Payment $payment): bool
    {
        return self::permits($user, 'manage');
    }

    public function create(Authenticatable $user): bool
    {
        return false;
    }

    public function update(Authenticatable $user, Payment $payment): bool
    {
        return false;
    }

    public function delete(Authenticatable $user, Payment $payment): bool
    {
        return false;
    }

    public function deleteAny(Authenticatable $user): bool
    {
        return false;
    }
}
