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

    /** Someone else's 2FA, e.g. after they lost their phone. Your own is managed on your profile. */
    public function resetTwoFactor(User $user, User $target): bool
    {
        // Only a super admin may touch a super admin's, so an ordinary admin
        // cannot weaken the strongest account in the system.
        if ($target->hasRole('super-admin') && ! $user->hasRole('super-admin')) {
            return false;
        }

        return $user->can('users.reset_two_factor') && $user->isNot($target);
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
