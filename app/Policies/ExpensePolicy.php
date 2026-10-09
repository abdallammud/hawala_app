<?php

namespace App\Policies;

use App\Models\Expense;
use App\Models\User;

class ExpensePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canAny(['expenses.view_own', 'expenses.view_branch', 'expenses.view_all']);
    }

    public function view(User $user, Expense $expense): bool
    {
        return $this->viewAny($user)
            && Expense::query()->visibleTo($user)->whereKey($expense->id)->exists();
    }

    public function create(User $user): bool
    {
        return $user->can('expenses.create');
    }

    public function update(User $user, Expense $expense): bool
    {
        return $user->can('expenses.edit') && ! $expense->isVoided() && $this->view($user, $expense);
    }

    public function void(User $user, Expense $expense): bool
    {
        return $user->can('expenses.void') && ! $expense->isVoided() && $this->view($user, $expense);
    }

    public function manageTypes(User $user): bool
    {
        return $user->can('expenses.types');
    }
}
