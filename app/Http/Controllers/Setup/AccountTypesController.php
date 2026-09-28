<?php

namespace App\Http\Controllers\Setup;

use App\Http\Controllers\Controller;
use App\Http\Requests\Setup\StoreAccountTypeRequest;
use App\Models\AccountType;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class AccountTypesController extends Controller
{
    public function index(): View
    {
        $this->authorize('setup.account_types');

        return view('setup.account-types.index', [
            'accountTypes' => AccountType::query()->withCount('payoutAccounts')->orderBy('sort')->orderBy('name')->get(),
        ]);
    }

    public function create(): View
    {
        $this->authorize('setup.account_types');

        return view('setup.account-types.create');
    }

    public function store(StoreAccountTypeRequest $request): RedirectResponse
    {
        AccountType::create($request->validated());

        return redirect()->route('setup.account-types.index')->with('status', __('Account type created.'));
    }

    public function edit(AccountType $accountType): View
    {
        $this->authorize('setup.account_types');

        return view('setup.account-types.edit', ['accountType' => $accountType]);
    }

    public function update(StoreAccountTypeRequest $request, AccountType $accountType): RedirectResponse
    {
        $accountType->update($request->validated());

        return redirect()->route('setup.account-types.index')->with('status', __('Account type updated.'));
    }

    public function toggle(AccountType $accountType): RedirectResponse
    {
        $this->authorize('setup.account_types');

        if ($accountType->is_active && $accountType->hasActivePayoutAccounts()) {
            return back()->withErrors(['accountType' => __('This account type is still used by active payout accounts and cannot be deactivated.')]);
        }

        $accountType->update(['is_active' => ! $accountType->is_active]);

        return back()->with('status', $accountType->is_active ? __('Account type activated.') : __('Account type deactivated.'));
    }
}
