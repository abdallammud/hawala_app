<?php

use App\Http\Controllers\DashboardController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/dashboard');

Route::middleware('auth')->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

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
    ))->name('transfers.create');

    Route::get('/transfers', $placeholder(
        __('All Transfers'), __('Search, filter and export every transfer.'), 5, 'arrow-right-left'
    ))->name('transfers.index');

    Route::get('/queue', $placeholder(
        __('Payout Queue'), __('Branch queue for payout agents to claim and pay transfers.'), 5, 'inbox-stack'
    ))->name('queue.index');

    Route::get('/balance-requests', $placeholder(
        __('Balance Requests'), __('Top-ups, credit, payout funding and commission moves.'), 3, 'banknotes'
    ))->name('balance-requests.index');

    Route::get('/commission', $placeholder(
        __('Commission'), __('Move commission to send balance or request a withdrawal.'), 3, 'gift'
    ))->name('commission.index');

    Route::get('/company-accounts', $placeholder(
        __('Company Accounts'), __('Balances and movements for every company account.'), 3, 'building-library'
    ))->name('company-accounts.index');

    Route::get('/users', $placeholder(
        __('Users'), __('Create and manage staff, sending agents and payout agents.'), 2, 'users'
    ))->name('users.index');

    Route::get('/setup', $placeholder(
        __('Setup'), __('Branches, account types, currencies, fees, settings, SMS templates and roles.'), 2, 'cog-6-tooth'
    ))->name('setup.index');

    Route::get('/reports', $placeholder(
        __('Reports'), __('Transfers, agent statements, uncollected, commissions, profit and more.'), 6, 'chart-bar'
    ))->name('reports.index');

    Route::get('/audit-log', $placeholder(
        __('Audit Log'), __('Every important action: who, what, when, before and after.'), 2, 'shield-check'
    ))->name('audit.index');

    Route::get('/notifications', $placeholder(
        __('Notifications'), __('Balance request updates, expired claims and large transfers.'), 3, 'bell'
    ))->name('notifications.index');
});

require __DIR__.'/auth.php';
