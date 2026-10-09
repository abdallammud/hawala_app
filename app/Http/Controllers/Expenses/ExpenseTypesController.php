<?php

namespace App\Http\Controllers\Expenses;

use App\Http\Controllers\Controller;
use App\Http\Requests\Expenses\StoreExpenseTypeRequest;
use App\Models\Expense;
use App\Models\ExpenseType;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class ExpenseTypesController extends Controller
{
    public function index(): View
    {
        $this->authorize('manageTypes', Expense::class);

        return view('expenses.types.index', [
            'types' => ExpenseType::query()->withCount('expenses')->orderBy('sort')->orderBy('name')->get(),
        ]);
    }

    public function store(StoreExpenseTypeRequest $request): RedirectResponse
    {
        ExpenseType::create([
            'name' => trim($request->string('name')->toString()),
            'sort' => (int) ExpenseType::query()->max('sort') + 1,
        ]);

        return redirect()->route('expense-types.index')->with('status', __('Expense type added.'));
    }

    public function edit(ExpenseType $expenseType): View
    {
        $this->authorize('manageTypes', Expense::class);

        return view('expenses.types.edit', ['type' => $expenseType]);
    }

    public function update(StoreExpenseTypeRequest $request, ExpenseType $expenseType): RedirectResponse
    {
        $expenseType->update(['name' => trim($request->string('name')->toString())]);

        return redirect()->route('expense-types.index')->with('status', __('Expense type renamed.'));
    }

    /** Types are switched off, never deleted — old expenses keep pointing at them. */
    public function toggle(ExpenseType $expenseType): RedirectResponse
    {
        $this->authorize('manageTypes', Expense::class);

        $expenseType->update(['is_active' => ! $expenseType->is_active]);

        return back()->with('status', $expenseType->is_active ? __('Expense type activated.') : __('Expense type deactivated.'));
    }
}
