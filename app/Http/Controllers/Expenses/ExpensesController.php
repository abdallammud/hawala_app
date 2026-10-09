<?php

namespace App\Http\Controllers\Expenses;

use App\Enums\ExpenseStatus;
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
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;
use RuntimeException;

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

        // What the filtered list adds up to — voided ones don't count.
        $total = (int) (clone $query)->where('status', ExpenseStatus::Posted)->sum('amount_cents');

        return view('expenses.index', [
            'expenses' => $query->with(['type', 'branch', 'createdBy'])->latest('expense_date')->latest('id')->paginate(20)->withQueryString(),
            'total' => $total,
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
        $account = CompanyAccount::findOrFail($request->integer('company_account_id'));

        try {
            $expense = $this->expenses->record(
                $request->user(),
                ExpenseType::findOrFail($request->integer('expense_type_id')),
                $account,
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
        } catch (InsufficientBalanceException) {
            return back()->withInput()->withErrors(['company_account_id' => __(':account does not have enough money for this expense.', ['account' => $account->name])]);
        } catch (RateUnavailableException $e) {
            return back()->withInput()->withErrors(['currency' => $e->getMessage()]);
        }

        return redirect()->route('expenses.show', $expense)->with('status', __('Expense recorded.'));
    }

    public function show(Expense $expense): View
    {
        $this->authorize('view', $expense);

        return view('expenses.show', [
            'expense' => $expense->load(['type', 'branch', 'companyAccount', 'createdBy', 'voidedBy']),
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
        ]);
    }

    public function update(UpdateExpenseRequest $request, Expense $expense): RedirectResponse
    {
        try {
            $this->expenses->updateDetails($expense, $request->validated());
        } catch (RuntimeException $e) {
            return back()->withErrors(['status' => $e->getMessage()]);
        }

        return redirect()->route('expenses.show', $expense)->with('status', __('Expense updated.'));
    }

    public function void(Request $request, Expense $expense): RedirectResponse
    {
        $this->authorize('void', $expense);

        $validated = $request->validate(['reason' => ['required', 'string', 'max:500']]);

        try {
            $this->expenses->void($expense, $request->user(), $validated['reason']);
        } catch (RuntimeException $e) {
            return back()->withErrors(['status' => $e->getMessage()]);
        }

        return back()->with('status', __('Expense voided — the money went back to the account.'));
    }
}
