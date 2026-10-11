<?php

namespace App\Http\Controllers\Expenses;

use App\Enums\ExpenseStatus;
use App\Exceptions\Balances\SelfApprovalException;
use App\Exceptions\Expenses\ExpenseStateException;
use App\Exceptions\Ledger\InsufficientBalanceException;
use App\Exceptions\Rates\RateUnavailableException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Expenses\StoreExpenseRequest;
use App\Http\Requests\Expenses\UpdateExpenseRequest;
use App\Models\Branch;
use App\Models\CompanyAccount;
use App\Models\Currency;
use App\Models\Expense;
use App\Models\ExpenseType;
use App\Services\Expenses\ExpenseService;
use App\Support\Brand;
use App\Support\Money;
use App\Support\Settings;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Spatie\Activitylog\Models\Activity;
use Symfony\Component\HttpFoundation\Response;

class ExpensesController extends Controller
{
    public function __construct(private ExpenseService $expenses) {}

    public function index(Request $request): View
    {
        $this->authorize('viewAny', Expense::class);

        $query = Expense::query()
            ->visibleTo($request->user())
            ->when($request->filled('search'), function ($q) use ($request) {
                $s = $request->string('search')->toString();
                $q->where(fn ($q) => $q->where('payee', 'like', "%{$s}%")
                    ->orWhere('reference', 'like', "%{$s}%")
                    ->orWhere('description', 'like', "%{$s}%"));
            })
            ->when($request->filled('type'), fn ($q) => $q->where('expense_type_id', $request->integer('type')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('branch_id'), fn ($q) => $q->where('branch_id', $request->integer('branch_id')))
            ->when($request->filled('from'), fn ($q) => $q->whereDate('expense_date', '>=', $request->string('from')))
            ->when($request->filled('to'), fn ($q) => $q->whereDate('expense_date', '<=', $request->string('to')));

        // What the filtered list adds up to. Only approved expenses have been
        // paid; pending ones are shown separately, rejected ones don't count.
        $total = (int) (clone $query)->where('status', ExpenseStatus::Approved)->sum('amount_cents');
        $pendingTotal = (int) (clone $query)->where('status', ExpenseStatus::Pending)->sum('amount_cents');

        return view('expenses.index', [
            'expenses' => $query->with(['type', 'branch', 'createdBy'])->latest('expense_date')->latest('id')->paginate(20)->withQueryString(),
            'total' => $total,
            'pendingTotal' => $pendingTotal,
            'types' => ExpenseType::query()->orderBy('sort')->orderBy('name')->pluck('name', 'id'),
            'branches' => $request->user()->can('expenses.view_all') ? Branch::query()->orderBy('name')->pluck('name', 'id') : collect(),
            'statuses' => ExpenseStatus::options(),
        ]);
    }

    public function create(Request $request): View
    {
        $this->authorize('create', Expense::class);

        return view('expenses.create', [
            'types' => ExpenseType::query()->where('is_active', true)->orderBy('sort')->orderBy('name')->pluck('name', 'id'),
            'accounts' => CompanyAccount::query()->where('is_active', true)->with('ledgerAccount')->orderBy('name')->get()
                ->mapWithKeys(fn (CompanyAccount $a) => [$a->id => $a->name.' — '.Money::format($a->ledgerAccount?->balance_cents ?? 0)]),
            'currencies' => Currency::query()->where('is_active', true)->orderBy('sort')->pluck('code', 'code'),
            'branch' => $request->user()->branch,
            'idempotencyKey' => $request->old('idempotency_key') ?: (string) Str::uuid(),
        ]);
    }

    public function store(StoreExpenseRequest $request): RedirectResponse
    {
        try {
            $expense = $this->expenses->record(
                $request->user(),
                ExpenseType::findOrFail($request->integer('expense_type_id')),
                CompanyAccount::findOrFail($request->integer('company_account_id')),
                [
                    'currency' => $request->string('currency')->toString(),
                    'amount_cents' => $request->amountCents(),
                    'expense_date' => $request->string('expense_date')->toString(),
                    'payee' => $request->input('payee'),
                    'reference' => $request->input('reference'),
                    'description' => $request->input('description'),
                ],
                $request->string('idempotency_key')->toString(),
            );
        } catch (RateUnavailableException $e) {
            return back()->withInput()->withErrors(['currency' => $e->getMessage()]);
        }

        // A repeated form token returns the earlier expense; don't attach twice.
        if ($expense->wasRecentlyCreated && $request->hasFile('attachment')) {
            $this->expenses->attach($expense, $request->file('attachment'));
        }

        return redirect()->route('expenses.show', $expense)->with('status', __('Expense :number recorded — it now waits for approval.', ['number' => $expense->number]));
    }

    public function show(Expense $expense): View
    {
        $this->authorize('view', $expense);

        return view('expenses.show', [
            'expense' => $expense->load(['type', 'branch', 'companyAccount', 'createdBy', 'decidedBy']),
            'history' => Activity::query()->forSubject($expense)->with('causer')->oldest('id')->get(),
        ]);
    }

    public function edit(Expense $expense): View
    {
        $this->authorize('update', $expense);

        return view('expenses.edit', [
            'expense' => $expense,
            'types' => ExpenseType::query()
                ->where(fn ($q) => $q->where('is_active', true)->orWhere('id', $expense->expense_type_id))
                ->orderBy('sort')->orderBy('name')->pluck('name', 'id'),
            'accounts' => CompanyAccount::query()
                ->where(fn ($q) => $q->where('is_active', true)->orWhere('id', $expense->company_account_id))
                ->orderBy('name')->pluck('name', 'id'),
            'currencies' => Currency::query()->where('is_active', true)->orderBy('sort')->pluck('code', 'code'),
        ]);
    }

    public function update(UpdateExpenseRequest $request, Expense $expense): RedirectResponse
    {
        try {
            $this->expenses->update($expense, [
                'expense_type_id' => $request->integer('expense_type_id'),
                'company_account_id' => $request->integer('company_account_id'),
                'currency' => $request->string('currency')->toString(),
                'amount_cents' => $request->amountCents(),
                'expense_date' => $request->string('expense_date')->toString(),
                'payee' => $request->input('payee'),
                'reference' => $request->input('reference'),
                'description' => $request->input('description'),
            ]);
        } catch (ExpenseStateException $e) {
            return back()->withErrors(['status' => $e->getMessage()]);
        } catch (RateUnavailableException $e) {
            return back()->withInput()->withErrors(['currency' => $e->getMessage()]);
        }

        if ($request->hasFile('attachment')) {
            $this->expenses->attach($expense, $request->file('attachment'));
        }

        return redirect()->route('expenses.show', $expense)->with('status', __('Expense updated.'));
    }

    public function approve(Request $request, Expense $expense): RedirectResponse
    {
        $this->authorize('decide', $expense);

        return $this->decide($request, fn (?string $reason) => $this->expenses->approve($expense, $request->user(), $reason),
            __('Expense :number approved — the money left :account.', ['number' => $expense->number, 'account' => $expense->companyAccount->name]));
    }

    public function reject(Request $request, Expense $expense): RedirectResponse
    {
        $this->authorize('decide', $expense);

        return $this->decide($request, fn (?string $reason) => $this->expenses->reject($expense, $request->user(), $reason),
            __('Expense :number rejected.', ['number' => $expense->number]));
    }

    public function reopen(Request $request, Expense $expense): RedirectResponse
    {
        $this->authorize('reopen', $expense);

        return $this->decide($request, fn (?string $reason) => $this->expenses->returnToPending($expense, $request->user(), $reason),
            __('Expense :number is pending again.', ['number' => $expense->number]));
    }

    public function destroy(Expense $expense): RedirectResponse
    {
        $this->authorize('delete', $expense);

        try {
            $this->expenses->delete($expense, request()->user());
        } catch (ExpenseStateException $e) {
            return back()->withErrors(['status' => $e->getMessage()]);
        }

        return redirect()->route('expenses.index')->with('status', __('Expense :number deleted.', ['number' => $expense->number]));
    }

    /** The voucher: a one-page PDF with the logo, opened in the browser so it can be printed. */
    public function print(Expense $expense): Response
    {
        $this->authorize('view', $expense);

        $expense->load(['type', 'branch', 'companyAccount', 'createdBy', 'decidedBy']);

        return Pdf::loadView('expenses.voucher', [
            'expense' => $expense,
            'companyName' => Brand::companyName(),
            'logo' => Brand::logoDataUri(),
            'timezone' => Settings::get('general.timezone', 'Africa/Mogadishu'),
        ])->setPaper('a4')->stream("voucher-{$expense->number}.pdf");
    }

    /** The receipt scan or photo. Private storage, so it goes through the same view check as the expense. */
    public function attachment(Expense $expense): Response
    {
        $this->authorize('view', $expense);

        abort_unless($expense->hasAttachment() && Storage::disk('local')->exists($expense->attachment_path), 404);

        return Storage::disk('local')->response($expense->attachment_path, $expense->attachment_name);
    }

    /** Shared by approve / reject / reopen: read the optional reason, run it, report any refusal. */
    private function decide(Request $request, \Closure $action, string $success): RedirectResponse
    {
        $validated = $request->validate(['reason' => ['nullable', 'string', 'max:500']]);

        try {
            $action($validated['reason'] ?? null);
        } catch (SelfApprovalException|ExpenseStateException $e) {
            return back()->withErrors(['status' => $e->getMessage()]);
        } catch (InsufficientBalanceException) {
            return back()->withErrors(['status' => __('The company account does not have enough money to pay this expense.')]);
        }

        return back()->with('status', $success);
    }
}
