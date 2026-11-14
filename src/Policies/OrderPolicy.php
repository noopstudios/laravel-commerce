<?php

namespace Noop\Commerce\Policies;

use Illuminate\Contracts\Auth\Authenticatable;
use Noop\Commerce\Models\Order;
use Noop\Commerce\Policies\Concerns\ChecksPermission;

/**
 * Orders are only read in the panel: the host's checkout creates them, and they change only through
 * the actions, which need `manage`.
 */
class OrderPolicy
{
    use ChecksPermission;

    public function viewAny(Authenticatable $user): bool
    {
        return self::sees($user);
    }

    public function view(Authenticatable $user, Order $order): bool
    {
        return self::sees($user);
    }

    public function manage(Authenticatable $user, Order $order): bool
    {
        return self::permits($user, 'manage');
    }

    public function create(Authenticatable $user): bool
    {
        return false;
    }

    public function update(Authenticatable $user, Order $order): bool
    {
        return false;
    }

    public function delete(Authenticatable $user, Order $order): bool
    {
        return false;
    }

    public function deleteAny(Authenticatable $user): bool
    {
        return false;
    }
}
