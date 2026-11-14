<?php

namespace Noop\Commerce\Policies\Concerns;

use Illuminate\Contracts\Auth\Access\Authorizable;

trait ChecksPermission
{
    /**
     * Whether the user has the ability named in `commerce.permissions.{$key}`. A null or empty
     * permission never grants.
     */
    protected static function permits(mixed $user, string $key): bool
    {
        $permission = config("commerce.permissions.{$key}");

        return $user instanceof Authorizable
            && is_string($permission)
            && $permission !== ''
            && $user->can($permission);
    }

    /**
     * Whoever manages also sees.
     */
    protected static function sees(mixed $user): bool
    {
        return self::permits($user, 'view') || self::permits($user, 'manage');
    }
}
