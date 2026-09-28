<?php

namespace App\Policies;

use App\Models\User;

class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('users.view');
    }

    public function view(User $user): bool
    {
        return $user->can('users.view');
    }

    public function create(User $user): bool
    {
        return $user->can('users.create');
    }

    public function update(User $user): bool
    {
        return $user->can('users.edit');
    }

    public function deactivate(User $user, User $target): bool
    {
        return $user->can('users.deactivate') && $user->isNot($target);
    }

    public function setCommission(User $user): bool
    {
        return $user->can('users.set_commission');
    }

    public function setCreditLimit(User $user): bool
    {
        return $user->can('users.set_credit_limit');
    }

    public function managePayoutAccounts(User $user): bool
    {
        return $user->can('users.manage_payout_accounts');
    }
}
