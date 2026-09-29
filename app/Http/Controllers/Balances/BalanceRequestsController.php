<?php

namespace App\Http\Controllers\Balances;

use App\Enums\BalanceRequestKind;
use App\Exceptions\Balances\MaxCreditExceededException;
use App\Exceptions\Balances\SelfApprovalException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Balances\StoreBalanceRequestRequest;
use App\Models\BalanceRequest;
use App\Models\CompanyAccount;
use App\Models\User;
use App\Services\Balances\BalanceRequestService;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class BalanceRequestsController extends Controller
{
    public function __construct(private BalanceRequestService $balanceRequests) {}

    public function index(Request $request): View
    {
        $this->authorize('balances.request');

        $requests = BalanceRequest::query()
            ->where('user_id', $request->user()->id)
            ->with(['companyAccount', 'decidedBy'])
            ->latest()
            ->paginate(20);

        return view('balance-requests.index', ['requests' => $requests]);
    }

    public function create(Request $request): View
    {
        $this->authorize('balances.request');

        $canActOnBehalf = collect(BalanceRequestKind::cases())
            ->contains(fn (BalanceRequestKind $kind) => $request->user()->can($kind->approvePermission()));

        return view('balance-requests.create', [
            'kinds' => BalanceRequestKind::requestableBy($request->user()),
            'companyAccounts' => CompanyAccount::query()->where('is_active', true)->orderBy('name')->pluck('name', 'id'),
            'beneficiaries' => $canActOnBehalf
                ? User::query()->where('is_active', true)->where('id', '!=', $request->user()->id)->orderBy('name')->pluck('name', 'id')
                : collect(),
        ]);
    }

    public function store(StoreBalanceRequestRequest $request): RedirectResponse
    {
        $kind = BalanceRequestKind::from($request->string('kind')->toString());
        $beneficiary = $request->filled('user_id') ? User::findOrFail($request->input('user_id')) : $request->user();
        $amountCents = Money::parseCents($request->string('amount'));
        $companyAccount = $request->filled('company_account_id') ? CompanyAccount::find($request->input('company_account_id')) : null;
        $note = $request->input('note');

        try {
            if ($request->user()->isNot($beneficiary) && $request->user()->can($kind->approvePermission())) {
                $this->balanceRequests->actDirectly($kind, $beneficiary, $amountCents, $request->user(), $companyAccount, $note);
                $message = __('Recorded and approved.');
            } else {
                $this->balanceRequests->submit($kind, $beneficiary, $amountCents, $request->user(), $companyAccount, $note);
                $message = __('Request submitted for approval.');
            }
        } catch (SelfApprovalException|MaxCreditExceededException $e) {
            return back()->withInput()->withErrors(['amount' => $e->getMessage()]);
        }

        return redirect()->route('balance-requests.index')->with('status', $message);
    }

    public function inbox(Request $request): View
    {
        $this->authorize('balances.view_all');

        $requests = BalanceRequest::query()
            ->with(['user', 'requestedBy', 'companyAccount'])
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when(! $request->filled('status'), fn ($q) => $q->orderByRaw("status = 'pending' desc"))
            ->latest()
            ->paginate(20)
            ->withQueryString();

        return view('balance-requests.inbox', ['requests' => $requests]);
    }

    public function approve(Request $request, BalanceRequest $balanceRequest): RedirectResponse
    {
        $this->authorize($balanceRequest->kind->approvePermission());

        try {
            $this->balanceRequests->approve($balanceRequest, $request->user());
        } catch (SelfApprovalException|MaxCreditExceededException $e) {
            return back()->withErrors(['status' => $e->getMessage()]);
        }

        return back()->with('status', __('Request approved.'));
    }

    public function reject(Request $request, BalanceRequest $balanceRequest): RedirectResponse
    {
        $this->authorize($balanceRequest->kind->approvePermission());

        $validated = $request->validate(['reason' => ['required', 'string', 'max:500']]);

        try {
            $this->balanceRequests->reject($balanceRequest, $request->user(), $validated['reason']);
        } catch (SelfApprovalException $e) {
            return back()->withErrors(['status' => $e->getMessage()]);
        }

        return back()->with('status', __('Request rejected.'));
    }
}
