<?php

namespace App\Providers;

use App\Models\Expense;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // A super-admin passes every permission check. The exception is a check
        // about one particular expense: its rules are about state and ownership
        // (only a pending expense is editable, you cannot approve your own),
        // and no role may skip them. ExpensePolicy still lets a super-admin
        // through on permissions, because `can('expenses.approve')` is not
        // about a model.
        Gate::before(function (User $user, string $ability, array $arguments = []) {
            if (($arguments[0] ?? null) instanceof Expense) {
                return null;
            }

            return $user->hasRole('super-admin') ?: null;
        });
    }
}
