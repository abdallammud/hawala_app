<?php

namespace App\Policies;

use App\Models\Expense;
use App\Models\User;

/**
 * Checks about a particular expense are not skipped by Gate::before, even for
 * a super-admin (see AppServiceProvider): state and ownership always apply.
 * ExpenseService enforces the same rules again under a row lock.
 */
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

    /** Only while pending: an approved or rejected expense is locked. */
    public function update(User $user, Expense $expense): bool
    {
        return $user->can('expenses.edit') && $expense->isPending() && $this->view($user, $expense);
    }

    /**
     * Approve or reject: not your own expense. Whether it is still pending is
     * the service's call, so a double click gets a clear message, not a 403.
     */
    public function decide(User $user, Expense $expense): bool
    {
        return $user->can('expenses.approve')
            && ! $expense->isOwnedBy($user)
            && $this->view($user, $expense);
    }

    /** Undo an approval or rejection. Same rule: not your own expense. */
    public function reopen(User $user, Expense $expense): bool
    {
        return $this->decide($user, $expense);
    }

    /** Whether an approved one may go is the service's call: it must be returned to pending first. */
    public function delete(User $user, Expense $expense): bool
    {
        return $user->can('expenses.delete') && $this->view($user, $expense);
    }

    public function manageTypes(User $user): bool
    {
        return $user->can('expenses.types');
    }
}
