<?php

namespace App\Http\Controllers\Balances;

use App\Enums\LedgerAccountType;
use App\Exceptions\Balances\SelfApprovalException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Balances\StoreAdjustmentRequest;
use App\Models\AdjustmentRequest;
use App\Models\LedgerAccount;
use App\Services\Balances\AdjustmentService;
use App\Services\Ledger\LedgerService;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AdjustmentsController extends Controller
{
    public function __construct(
        private AdjustmentService $adjustments,
        private LedgerService $ledger,
    ) {}

    public function index(Request $request): View
    {
        if (! $request->user()->canAny(['adjustments.create', 'adjustments.approve'])) {
            abort(403);
        }

        $requests = AdjustmentRequest::query()
            ->with(['debitLedgerAccount', 'creditLedgerAccount', 'requestedBy', 'decidedBy'])
            ->latest()
            ->paginate(20);

        return view('adjustments.index', ['requests' => $requests]);
    }

    public function create(): View
    {
        $this->authorize('adjustments.create');

        // System accounts (fee_income, adjustments, …) are normally created
        // lazily on first real use. The canonical manual_adjustment use case
        // (BUILD.md §4 — "Dr Mogadishu bank / Cr adjustments" to seed an
        // opening balance) needs the "adjustments" account to be pickable
        // even before anything has ever posted to it, so ensure all system
        // accounts exist before listing options. Harmless: these are
        // well-known singletons and creating one with a zero balance changes
        // nothing observable.
        foreach ($this->systemAccountTypes() as $type) {
            $this->ledger->system($type);
        }

        return view('adjustments.create', [
            'accounts' => $this->accountOptions(),
        ]);
    }

    public function store(StoreAdjustmentRequest $request): RedirectResponse
    {
        $debit = LedgerAccount::findOrFail($request->input('debit_ledger_account_id'));
        $credit = LedgerAccount::findOrFail($request->input('credit_ledger_account_id'));

        $this->adjustments->submit(
            $debit,
            $credit,
            Money::parseCents($request->string('amount')),
            $request->string('reason'),
            $request->user()
        );

        return redirect()->route('adjustments.index')->with('status', __('Adjustment submitted for approval.'));
    }

    public function approve(Request $request, AdjustmentRequest $adjustmentRequest): RedirectResponse
    {
        $this->authorize('adjustments.approve');

        try {
            $this->adjustments->approve($adjustmentRequest, $request->user());
        } catch (SelfApprovalException $e) {
            return back()->withErrors(['status' => $e->getMessage()]);
        }

        return back()->with('status', __('Adjustment approved.'));
    }

    public function reject(Request $request, AdjustmentRequest $adjustmentRequest): RedirectResponse
    {
        $this->authorize('adjustments.approve');

        $validated = $request->validate(['reason' => ['required', 'string', 'max:500']]);

        try {
            $this->adjustments->reject($adjustmentRequest, $request->user(), $validated['reason']);
        } catch (SelfApprovalException $e) {
            return back()->withErrors(['status' => $e->getMessage()]);
        }

        return back()->with('status', __('Adjustment rejected.'));
    }

    private function accountOptions(): array
    {
        return LedgerAccount::query()
            ->orderBy('name')
            ->get()
            ->mapWithKeys(fn (LedgerAccount $account) => [$account->id => $account->owner ? $account->name : "{$account->name} (system)"])
            ->all();
    }

    /** @return LedgerAccountType[] */
    private function systemAccountTypes(): array
    {
        return [
            LedgerAccountType::TransfersPayable,
            LedgerAccountType::FeeIncome,
            LedgerAccountType::FxIncome,
            LedgerAccountType::CommissionExpense,
            LedgerAccountType::BankCharges,
            LedgerAccountType::Adjustments,
        ];
    }
}
