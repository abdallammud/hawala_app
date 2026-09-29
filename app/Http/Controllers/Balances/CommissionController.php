<?php

namespace App\Http\Controllers\Balances;

use App\Enums\BalanceRequestKind;
use App\Enums\LedgerAccountType;
use App\Http\Controllers\Controller;
use App\Models\BalanceRequest;
use App\Models\CompanyAccount;
use App\Services\Balances\BalanceRequestService;
use App\Services\Ledger\LedgerService;
use App\Support\Money;
use App\Support\Settings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class CommissionController extends Controller
{
    public function __construct(
        private LedgerService $ledger,
        private BalanceRequestService $balanceRequests,
    ) {}

    public function index(Request $request): View
    {
        $this->authorize('commission.view_own');

        $user = $request->user();
        $commission = $this->ledger->accountFor($user, LedgerAccountType::AgentCommission);

        $history = BalanceRequest::query()
            ->where('user_id', $user->id)
            ->whereIn('kind', [BalanceRequestKind::CommissionMove, BalanceRequestKind::CommissionWithdraw])
            ->latest()
            ->paginate(15);

        return view('commission.index', [
            'balanceCents' => $commission->balance_cents,
            'history' => $history,
            'moveRequiresApproval' => Settings::get('commission.move_requires_approval', false),
            'companyAccounts' => CompanyAccount::query()->where('is_active', true)->orderBy('name')->pluck('name', 'id'),
        ]);
    }

    public function move(Request $request): RedirectResponse
    {
        $this->authorize('commission.move');

        $validated = $request->validate(['amount' => ['required', 'numeric', 'min:0.01']]);
        $amountCents = Money::parseCents($validated['amount']);
        $user = $request->user();

        if (Settings::get('commission.move_requires_approval', false)) {
            $this->balanceRequests->submit(BalanceRequestKind::CommissionMove, $user, $amountCents, $user);
            $message = __('Move request submitted for approval.');
        } else {
            $this->balanceRequests->moveCommissionInstant($user, $amountCents);
            $message = __('Commission moved to your send balance.');
        }

        return back()->with('status', $message);
    }

    public function withdraw(Request $request): RedirectResponse
    {
        $this->authorize('commission.request_withdraw');

        $validated = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01'],
            'company_account_id' => ['required', 'exists:company_accounts,id'],
        ]);

        $this->balanceRequests->submit(
            BalanceRequestKind::CommissionWithdraw,
            $request->user(),
            Money::parseCents($validated['amount']),
            $request->user(),
            CompanyAccount::find($validated['company_account_id']),
        );

        return back()->with('status', __('Withdrawal request submitted for approval.'));
    }
}
