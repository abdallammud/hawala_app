<?php

namespace App\Http\Controllers\Users;

use App\Http\Controllers\Controller;
use App\Http\Requests\Users\StorePayoutAccountRequest;
use App\Models\PayoutAccount;
use App\Models\User;
use Illuminate\Http\RedirectResponse;

class PayoutAccountsController extends Controller
{
    public function store(StorePayoutAccountRequest $request, User $user): RedirectResponse
    {
        $user->payoutAccounts()->create($request->validated());

        return back()->with('status', __('Payout account added.'));
    }

    public function update(StorePayoutAccountRequest $request, User $user, PayoutAccount $payoutAccount): RedirectResponse
    {
        $payoutAccount->update($request->validated());

        return back()->with('status', __('Payout account updated.'));
    }

    public function toggle(User $user, PayoutAccount $payoutAccount): RedirectResponse
    {
        $this->authorize('managePayoutAccounts', User::class);

        $payoutAccount->update(['is_active' => ! $payoutAccount->is_active]);

        return back()->with('status', $payoutAccount->is_active ? __('Payout account enabled.') : __('Payout account disabled.'));
    }
}
