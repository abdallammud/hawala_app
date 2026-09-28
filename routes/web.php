<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\Users\PayoutAccountsController;
use App\Http\Controllers\Users\RolesController;
use App\Http\Controllers\Users\UsersController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/dashboard');

Route::middleware('auth')->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

    Route::middleware('can:users.view')->group(function () {
        Route::get('/users', [UsersController::class, 'index'])->name('users.index');
        Route::get('/users/{user}/edit', [UsersController::class, 'edit'])->name('users.edit');
        Route::put('/users/{user}', [UsersController::class, 'update'])->name('users.update');

        Route::post('/users/{user}/payout-accounts', [PayoutAccountsController::class, 'store'])
            ->middleware('can:users.manage_payout_accounts')->name('users.payout-accounts.store');
        Route::put('/users/{user}/payout-accounts/{payoutAccount}', [PayoutAccountsController::class, 'update'])
            ->middleware('can:users.manage_payout_accounts')->name('users.payout-accounts.update');
        Route::post('/users/{user}/payout-accounts/{payoutAccount}/toggle', [PayoutAccountsController::class, 'toggle'])
            ->middleware('can:users.manage_payout_accounts')->name('users.payout-accounts.toggle');
    });

    Route::middleware('can:users.create')->group(function () {
        Route::get('/users/create', [UsersController::class, 'create'])->name('users.create');
        Route::post('/users', [UsersController::class, 'store'])->name('users.store');
    });

    Route::middleware('can:users.deactivate')->group(function () {
        Route::post('/users/{user}/deactivate', [UsersController::class, 'deactivate'])->name('users.deactivate');
        Route::post('/users/{user}/activate', [UsersController::class, 'activate'])->name('users.activate');
    });

    Route::middleware('can:setup.roles')->group(function () {
        Route::get('/users/roles', [RolesController::class, 'index'])->name('roles.index');
        Route::get('/users/roles/create', [RolesController::class, 'create'])->name('roles.create');
        Route::post('/users/roles', [RolesController::class, 'store'])->name('roles.store');
        Route::get('/users/roles/{role}/edit', [RolesController::class, 'edit'])->name('roles.edit');
        Route::put('/users/roles/{role}', [RolesController::class, 'update'])->name('roles.update');
        Route::delete('/users/roles/{role}', [RolesController::class, 'destroy'])->name('roles.destroy');
    });

    // --- Placeholder screens -------------------------------------------------
    // Each of these is replaced by a real controller + views as its BUILD.md
    // phase is implemented. Kept here so the full navigation can be reviewed
    // and clicked through before any single module is wired up.
    $placeholder = fn (string $title, string $description, int $phase, string $icon) => fn () => view('placeholder', [
        'title' => $title,
        'description' => $description,
        'phase' => $phase,
        'icon' => $icon,
    ]);

    Route::get('/transfers/create', $placeholder(
        __('New Transfer'), __('Record money a customer wants sent to Somalia.'), 5, 'plus-circle'
    ))->middleware('can:transfers.create')->name('transfers.create');

    Route::get('/transfers', $placeholder(
        __('All Transfers'), __('Search, filter and export every transfer.'), 5, 'arrow-right-left'
    ))->middleware('can:transfers.view_own')->name('transfers.index');

    Route::get('/queue', $placeholder(
        __('Payout Queue'), __('Branch queue for payout agents to claim and pay transfers.'), 5, 'inbox-stack'
    ))->middleware('can:queue.view')->name('queue.index');

    Route::get('/balance-requests', $placeholder(
        __('Balance Requests'), __('Top-ups, credit, payout funding and commission moves.'), 3, 'banknotes'
    ))->middleware('can:balances.request')->name('balance-requests.index');

    Route::get('/commission', $placeholder(
        __('Commission'), __('Move commission to send balance or request a withdrawal.'), 3, 'gift'
    ))->middleware('can:commission.view_own')->name('commission.index');

    Route::get('/company-accounts', $placeholder(
        __('Company Accounts'), __('Balances and movements for every company account.'), 3, 'building-library'
    ))->middleware('can:company_accounts.view')->name('company-accounts.index');

    Route::get('/setup', $placeholder(
        __('Setup'), __('Branches, account types, currencies, fees, settings, SMS templates and roles.'), 2, 'cog-6-tooth'
    ))->middleware('can:setup.branches')->name('setup.index');

    Route::get('/reports', $placeholder(
        __('Reports'), __('Transfers, agent statements, uncollected, commissions, profit and more.'), 6, 'chart-bar'
    ))->middleware('can:reports.agent_statement')->name('reports.index');

    Route::get('/audit-log', $placeholder(
        __('Audit Log'), __('Every important action: who, what, when, before and after.'), 2, 'shield-check'
    ))->middleware('can:audit.view')->name('audit.index');

    Route::get('/notifications', $placeholder(
        __('Notifications'), __('Balance request updates, expired claims and large transfers.'), 3, 'bell'
    ))->name('notifications.index');
});

require __DIR__.'/auth.php';
