<?php

use App\Http\Controllers\Balances\AdjustmentsController;
use App\Http\Controllers\Balances\BalanceRequestsController;
use App\Http\Controllers\Balances\CommissionController;
use App\Http\Controllers\Balances\CompanyAccountsController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\NotificationsController;
use App\Http\Controllers\Setup\AccountTypesController;
use App\Http\Controllers\Setup\BranchesController;
use App\Http\Controllers\Setup\CurrenciesController;
use App\Http\Controllers\Setup\FeeTiersController;
use App\Http\Controllers\Setup\SettingsController;
use App\Http\Controllers\Setup\SetupController;
use App\Http\Controllers\Transfers\CancellationRequestsController;
use App\Http\Controllers\Transfers\PayoutQueueController;
use App\Http\Controllers\Transfers\TransferQuoteController;
use App\Http\Controllers\Transfers\TransfersController;
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
        Route::get('/users/{user}/statement', [UsersController::class, 'statement'])->name('users.statement');

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

    Route::get('/setup', [SetupController::class, 'index'])->middleware('can:setup.branches')->name('setup.index');

    Route::prefix('setup/branches')->middleware('can:setup.branches')->name('setup.branches.')->group(function () {
        Route::get('/', [BranchesController::class, 'index'])->name('index');
        Route::get('/create', [BranchesController::class, 'create'])->name('create');
        Route::post('/', [BranchesController::class, 'store'])->name('store');
        Route::get('/{branch}/edit', [BranchesController::class, 'edit'])->name('edit');
        Route::put('/{branch}', [BranchesController::class, 'update'])->name('update');
        Route::post('/{branch}/toggle', [BranchesController::class, 'toggle'])->name('toggle');
    });

    Route::prefix('setup/account-types')->middleware('can:setup.account_types')->name('setup.account-types.')->group(function () {
        Route::get('/', [AccountTypesController::class, 'index'])->name('index');
        Route::get('/create', [AccountTypesController::class, 'create'])->name('create');
        Route::post('/', [AccountTypesController::class, 'store'])->name('store');
        Route::get('/{accountType}/edit', [AccountTypesController::class, 'edit'])->name('edit');
        Route::put('/{accountType}', [AccountTypesController::class, 'update'])->name('update');
        Route::post('/{accountType}/toggle', [AccountTypesController::class, 'toggle'])->name('toggle');
    });

    Route::prefix('setup/currencies')->middleware('can:setup.currencies')->name('setup.currencies.')->group(function () {
        Route::get('/', [CurrenciesController::class, 'index'])->name('index');
        Route::put('/{currency}', [CurrenciesController::class, 'update'])->name('update');
        Route::post('/fetch', [CurrenciesController::class, 'fetch'])->name('fetch');
    });

    Route::prefix('setup/fee-tiers')->middleware('can:setup.fees')->name('setup.fee-tiers.')->group(function () {
        Route::get('/', [FeeTiersController::class, 'index'])->name('index');
        Route::put('/', [FeeTiersController::class, 'update'])->name('update');
        Route::post('/preview', [FeeTiersController::class, 'preview'])->name('preview');
    });

    Route::prefix('setup/settings')->middleware('can:setup.settings')->name('setup.settings.')->group(function () {
        Route::get('/', [SettingsController::class, 'edit'])->name('edit');
        Route::put('/', [SettingsController::class, 'update'])->name('update');
    });

    // --- Transfers -----------------------------------------------------------
    // Static segments before the {transfer} wildcard, same reasoning as
    // company accounts above.
    Route::post('/transfers/quote', TransferQuoteController::class)
        ->middleware('can:transfers.create')->name('transfers.quote');

    Route::middleware('can:transfers.create')->group(function () {
        Route::get('/transfers/create', [TransfersController::class, 'create'])->name('transfers.create');
        Route::post('/transfers', [TransfersController::class, 'store'])->name('transfers.store');
    });

    Route::get('/transfers', [TransfersController::class, 'index'])->name('transfers.index');

    Route::middleware('can:transfers.approve_cancel')->group(function () {
        Route::get('/cancellation-requests', [CancellationRequestsController::class, 'index'])->name('cancellation-requests.index');
        Route::post('/cancellation-requests/{cancellationRequest}/approve', [CancellationRequestsController::class, 'approve'])->name('cancellation-requests.approve');
        Route::post('/cancellation-requests/{cancellationRequest}/reject', [CancellationRequestsController::class, 'reject'])->name('cancellation-requests.reject');
    });

    Route::get('/transfers/{transfer}', [TransfersController::class, 'show'])->name('transfers.show');
    Route::post('/transfers/{transfer}/cancel', [TransfersController::class, 'cancel'])->name('transfers.cancel');
    Route::post('/transfers/{transfer}/request-cancel', [TransfersController::class, 'requestCancel'])->name('transfers.request-cancel');
    Route::post('/transfers/{transfer}/approve-large', [TransfersController::class, 'approveLarge'])->name('transfers.approve-large');
    Route::post('/transfers/{transfer}/reject-large', [TransfersController::class, 'rejectLarge'])->name('transfers.reject-large');
    Route::post('/transfers/{transfer}/collections', [TransfersController::class, 'recordCollection'])->name('transfers.collections.store');

    // --- Payout queue --------------------------------------------------------
    Route::middleware('can:queue.view')->group(function () {
        Route::get('/queue', [PayoutQueueController::class, 'index'])->name('queue.index');
    });

    Route::post('/queue/{transfer}/claim', [PayoutQueueController::class, 'claim'])->name('queue.claim');
    Route::post('/queue/{transfer}/mark-paid', [PayoutQueueController::class, 'markPaid'])->name('queue.mark-paid');
    Route::post('/queue/{transfer}/release', [PayoutQueueController::class, 'release'])->name('queue.release');
    Route::post('/queue/{transfer}/force-assign', [PayoutQueueController::class, 'forceAssign'])->name('queue.force-assign');

    // --- Balance requests ------------------------------------------------
    Route::middleware('can:balances.request')->group(function () {
        Route::get('/balance-requests', [BalanceRequestsController::class, 'index'])->name('balance-requests.index');
        Route::get('/balance-requests/create', [BalanceRequestsController::class, 'create'])->name('balance-requests.create');
        Route::post('/balance-requests', [BalanceRequestsController::class, 'store'])->name('balance-requests.store');
    });

    Route::middleware('can:balances.view_all')->group(function () {
        Route::get('/balance-requests/inbox', [BalanceRequestsController::class, 'inbox'])->name('balance-requests.inbox');
    });

    Route::post('/balance-requests/{balanceRequest}/approve', [BalanceRequestsController::class, 'approve'])->name('balance-requests.approve');
    Route::post('/balance-requests/{balanceRequest}/reject', [BalanceRequestsController::class, 'reject'])->name('balance-requests.reject');

    // --- Commission --------------------------------------------------------
    Route::middleware('can:commission.view_own')->group(function () {
        Route::get('/commission', [CommissionController::class, 'index'])->name('commission.index');
    });
    Route::post('/commission/move', [CommissionController::class, 'move'])->middleware('can:commission.move')->name('commission.move');
    Route::post('/commission/withdraw', [CommissionController::class, 'withdraw'])->middleware('can:commission.request_withdraw')->name('commission.withdraw');

    // --- Company accounts ----------------------------------------------------
    // Static-segment routes (/create, /{id}/edit, …) must be registered before
    // the bare /{companyAccount} wildcard below, or Laravel tries to resolve
    // "create" etc. as a company account id and 404s.
    Route::middleware('can:company_accounts.view')->group(function () {
        Route::get('/company-accounts', [CompanyAccountsController::class, 'index'])->name('company-accounts.index');
    });

    Route::middleware('can:company_accounts.manage')->group(function () {
        Route::get('/company-accounts/create', [CompanyAccountsController::class, 'create'])->name('company-accounts.create');
        Route::post('/company-accounts', [CompanyAccountsController::class, 'store'])->name('company-accounts.store');
        Route::get('/company-accounts/{companyAccount}/edit', [CompanyAccountsController::class, 'edit'])->name('company-accounts.edit');
        Route::put('/company-accounts/{companyAccount}', [CompanyAccountsController::class, 'update'])->name('company-accounts.update');
        Route::post('/company-accounts/{companyAccount}/toggle', [CompanyAccountsController::class, 'toggle'])->name('company-accounts.toggle');
    });

    Route::middleware('can:company_accounts.view')->group(function () {
        Route::get('/company-accounts/{companyAccount}', [CompanyAccountsController::class, 'show'])->name('company-accounts.show');
    });

    Route::middleware('can:company_accounts.transfer')->group(function () {
        Route::get('/company-accounts-transfer', [CompanyAccountsController::class, 'transferCreate'])->name('company-accounts.transfer.create');
        Route::post('/company-accounts-transfer', [CompanyAccountsController::class, 'transferStore'])->name('company-accounts.transfer.store');
    });

    // --- Manual adjustments ---------------------------------------------------
    Route::get('/adjustments', [AdjustmentsController::class, 'index'])->name('adjustments.index');

    Route::middleware('can:adjustments.create')->group(function () {
        Route::get('/adjustments/create', [AdjustmentsController::class, 'create'])->name('adjustments.create');
        Route::post('/adjustments', [AdjustmentsController::class, 'store'])->name('adjustments.store');
    });

    Route::middleware('can:adjustments.approve')->group(function () {
        Route::post('/adjustments/{adjustmentRequest}/approve', [AdjustmentsController::class, 'approve'])->name('adjustments.approve');
        Route::post('/adjustments/{adjustmentRequest}/reject', [AdjustmentsController::class, 'reject'])->name('adjustments.reject');
    });

    // --- Notifications -------------------------------------------------------
    Route::get('/notifications', [NotificationsController::class, 'index'])->name('notifications.index');
    Route::post('/notifications/read', [NotificationsController::class, 'markRead'])->name('notifications.read');

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

    Route::get('/reports', $placeholder(
        __('Reports'), __('Transfers, agent statements, uncollected, commissions, profit and more.'), 6, 'chart-bar'
    ))->middleware('can:reports.agent_statement')->name('reports.index');

    Route::get('/audit-log', $placeholder(
        __('Audit Log'), __('Every important action: who, what, when, before and after.'), 2, 'shield-check'
    ))->middleware('can:audit.view')->name('audit.index');
});

require __DIR__.'/auth.php';
