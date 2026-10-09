<?php

namespace App\Policies;

use App\Models\Customer;
use App\Models\User;

class CustomerPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canAny(['customers.view_own', 'customers.view_all']);
    }

    /** Everyone with view_all; otherwise only customers they registered or have sent for. */
    public function view(User $user, Customer $customer): bool
    {
        if (! $this->viewAny($user)) {
            return false;
        }

        return Customer::query()->visibleTo($user)->whereKey($customer->id)->exists();
    }

    public function update(User $user, Customer $customer): bool
    {
        return $user->can('customers.edit') && $this->view($user, $customer);
    }
}
