<?php

namespace App\Http\Controllers\Balances;

use App\Enums\JournalType;
use App\Enums\LedgerAccountType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Balances\CompanyTransferRequest;
use App\Http\Requests\Balances\StoreCompanyAccountRequest;
use App\Models\CompanyAccount;
use App\Models\CompanyTransfer;
use App\Services\Ledger\LedgerService;
use App\Support\LedgerStatement;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class CompanyAccountsController extends Controller
{
    public function __construct(private LedgerService $ledger) {}

    public function index(): View
    {
        $this->authorize('company_accounts.view');

        $accounts = CompanyAccount::query()->with('ledgerAccount')->orderBy('name')->get();

        return view('company-accounts.index', ['accounts' => $accounts]);
    }

    public function create(): View
    {
        $this->authorize('company_accounts.manage');

        return view('company-accounts.create');
    }

    public function store(StoreCompanyAccountRequest $request): RedirectResponse
    {
        $account = CompanyAccount::create($request->validated() + ['is_active' => true]);
        $ledgerAccount = $this->ledger->accountFor($account, LedgerAccountType::Company);
        $account->update(['ledger_account_id' => $ledgerAccount->id]);

        return redirect()->route('company-accounts.index')->with('status', __('Company account created.'));
    }

    public function edit(CompanyAccount $companyAccount): View
    {
        $this->authorize('company_accounts.manage');

        return view('company-accounts.edit', ['account' => $companyAccount]);
    }

    public function update(StoreCompanyAccountRequest $request, CompanyAccount $companyAccount): RedirectResponse
    {
        $companyAccount->update($request->validated());

        return redirect()->route('company-accounts.index')->with('status', __('Company account updated.'));
    }

    public function toggle(CompanyAccount $companyAccount): RedirectResponse
    {
        $this->authorize('company_accounts.manage');

        $companyAccount->update(['is_active' => ! $companyAccount->is_active]);

        return back()->with('status', $companyAccount->is_active ? __('Company account activated.') : __('Company account deactivated.'));
    }

    public function show(CompanyAccount $companyAccount): View
    {
        $this->authorize('company_accounts.view');

        $ledgerAccount = $this->ledger->accountFor($companyAccount, LedgerAccountType::Company);

        return view('company-accounts.show', [
            'account' => $companyAccount,
            'statement' => LedgerStatement::build($ledgerAccount),
        ]);
    }

    public function transferCreate(): View
    {
        $this->authorize('company_accounts.transfer');

        return view('company-accounts.transfer', [
            'accounts' => CompanyAccount::query()->where('is_active', true)->orderBy('name')->pluck('name', 'id'),
        ]);
    }

    public function transferStore(CompanyTransferRequest $request): RedirectResponse
    {
        $from = CompanyAccount::findOrFail($request->input('from_company_account_id'));
        $to = CompanyAccount::findOrFail($request->input('to_company_account_id'));

        $sentCents = Money::parseCents($request->string('sent'));
        $receivedCents = Money::parseCents($request->string('received'));
        $chargesCents = $sentCents - $receivedCents;

        $fromLedger = $this->ledger->accountFor($from, LedgerAccountType::Company);
        $toLedger = $this->ledger->accountFor($to, LedgerAccountType::Company);
        $bankCharges = $this->ledger->system(LedgerAccountType::BankCharges);

        $lines = $chargesCents > 0
            ? [[$toLedger, $receivedCents], [$bankCharges, $chargesCents], [$fromLedger, -$sentCents]]
            : [[$toLedger, $receivedCents], [$fromLedger, -$sentCents]];

        $transfer = CompanyTransfer::create([
            'from_company_account_id' => $from->id,
            'to_company_account_id' => $to->id,
            'sent_cents' => $sentCents,
            'received_cents' => $receivedCents,
            'charges_cents' => $chargesCents,
            'reference' => $request->input('reference'),
            'transferred_at' => $request->date('transferred_at'),
            'created_by' => $request->user()->id,
        ]);

        $entry = $this->ledger->post(JournalType::CompanyTransfer, $lines, $transfer, $request->user());
        $transfer->update(['journal_entry_id' => $entry->id]);

        return redirect()->route('company-accounts.index')->with('status', __('Money moved between company accounts.'));
    }
}
