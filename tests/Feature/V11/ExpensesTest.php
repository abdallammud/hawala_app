<?php

use App\Enums\ExpenseStatus;
use App\Enums\JournalType;
use App\Enums\LedgerAccountType;
use App\Enums\UserType;
use App\Models\Branch;
use App\Models\CompanyAccount;
use App\Models\Currency;
use App\Models\Expense;
use App\Models\ExpenseType;
use App\Models\JournalLine;
use App\Models\User;
use App\Reports\ReportFilters;
use App\Reports\ReportRegistry;
use App\Services\Expenses\ExpenseService;
use App\Services\Ledger\LedgerService;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->branch = Branch::create(['name' => 'Mogadishu', 'city' => 'Mogadishu', 'is_payout_point' => true, 'is_active' => true]);
    $this->hargeisa = Branch::create(['name' => 'Hargeisa', 'city' => 'Hargeisa', 'is_payout_point' => true, 'is_active' => true]);
    Currency::query()->firstOrCreate(['code' => 'USD'], ['name' => 'US Dollar', 'is_active' => true]);
    $this->company = expenseCompany(100000); // $1,000
});

function expenseCompany(int $cents, string $name = 'Mogadishu Office'): CompanyAccount
{
    $ledger = app(LedgerService::class);
    $account = CompanyAccount::create(['name' => $name, 'location' => 'somalia', 'is_active' => true]);

    if ($cents > 0) {
        $ledger->post(
            JournalType::ManualAdjustment,
            [[$ledger->accountFor($account, LedgerAccountType::Company), $cents], [$ledger->system(LedgerAccountType::Adjustments), -$cents]],
            null,
            User::factory()->create(),
        );
    }

    return $account;
}

function companyBalance(CompanyAccount $a): int
{
    return app(LedgerService::class)->accountFor($a, LedgerAccountType::Company)->fresh()->balance_cents;
}

function expensesBalance(): int
{
    return app(LedgerService::class)->system(LedgerAccountType::Expenses)->fresh()->balance_cents;
}

/** A user who may record and view expenses, in $branch. */
function bookkeeper(Branch $branch, array $extra = ['expenses.view_own', 'expenses.create']): User
{
    $user = User::factory()->create(['branch_id' => $branch->id]);
    $user->givePermissionTo($extra);

    return $user;
}

function addExpense(User $by, array $overrides = [], ?CompanyAccount $from = null, ?ExpenseType $type = null): Expense
{
    return app(ExpenseService::class)->record(
        $by,
        $type ?? ExpenseType::where('name', 'Office Rent')->first(),
        $from ?? test()->company,
        array_merge(['amount_cents' => 25000, 'expense_date' => today()->toDateString(), 'payee' => 'Landlord'], $overrides),
        (string) Str::uuid(),
    );
}

function expFilters(array $o = []): ReportFilters
{
    return new ReportFilters(CarbonImmutable::now()->subYear(), CarbonImmutable::now()->addDay(), branchId: $o['branch'] ?? null, expenseTypeId: $o['type'] ?? null);
}

// --- Types -----------------------------------------------------------------------

it('starts with payroll, rent, bills and running cost', function () {
    expect(ExpenseType::orderBy('sort')->pluck('name')->all())
        ->toBe(['Staff Payroll', 'Office Rent', 'Bills (Internet & airtime)', 'Running Cost']);
});

it('lets the customer add, rename and switch off types', function () {
    $admin = makeAdmin();

    $this->actingAs($admin)->post('/expenses/types', ['name' => 'Security guard'])->assertRedirect('/expenses/types');
    $type = ExpenseType::where('name', 'Security guard')->firstOrFail();
    expect($type->is_active)->toBeTrue();

    $this->actingAs($admin)->post('/expenses/types', ['name' => 'Office Rent'])->assertSessionHasErrors('name');

    $this->actingAs($admin)->put("/expenses/types/{$type->id}", ['name' => 'Guards'])->assertRedirect();
    expect($type->fresh()->name)->toBe('Guards');

    $this->actingAs($admin)->post("/expenses/types/{$type->id}/toggle");
    expect($type->fresh()->is_active)->toBeFalse();

    // A switched-off type is no longer offered, nor accepted.
    $this->actingAs($admin)->get('/expenses/create')->assertOk()->assertDontSee('Guards')->assertSee('Office Rent');
    $this->actingAs($admin)->post('/expenses', expenseForm(['expense_type_id' => $type->id]))->assertSessionHasErrors('expense_type_id');
});

function expenseForm(array $o = []): array
{
    return array_merge([
        'idempotency_key' => (string) Str::uuid(),
        'expense_type_id' => ExpenseType::where('name', 'Office Rent')->value('id'),
        'company_account_id' => test()->company->id,
        'currency' => 'USD',
        'amount' => '250',
        'expense_date' => today()->toDateString(),
        'payee' => 'Landlord',
    ], $o);
}

it('keeps expense types to people who may manage them', function () {
    $clerk = bookkeeper($this->branch);

    $this->actingAs($clerk)->get('/expenses/types')->assertForbidden();
    $this->actingAs($clerk)->post('/expenses/types', ['name' => 'X'])->assertForbidden();
    $this->actingAs($clerk)->get('/expenses')->assertOk()->assertDontSee('Expense types');
    $this->actingAs(makeAdmin())->get('/expenses')->assertOk()->assertSee('Expense types');
});

// --- Recording ----------------------------------------------------------------------

it('pays an expense out of the company account through the ledger', function () {
    $clerk = bookkeeper($this->branch);

    $this->actingAs($clerk)->post('/expenses', expenseForm())->assertRedirect();

    $expense = Expense::sole();

    expect($expense->amount_cents)->toBe(25000)
        ->and($expense->branch_id)->toBe($this->branch->id)
        ->and($expense->created_by)->toBe($clerk->id)
        ->and($expense->status)->toBe(ExpenseStatus::Posted)
        ->and($expense->journal_entry_id)->not->toBeNull()
        ->and(companyBalance($this->company))->toBe(75000)
        ->and(expensesBalance())->toBe(25000)
        ->and(JournalLine::sum('amount_cents'))->toBe(0);

    $this->artisan('hawala:verify-ledger')->assertSuccessful();
});

it('takes the branch from the user, never from the form', function () {
    $clerk = bookkeeper($this->hargeisa);

    $this->actingAs($clerk)->post('/expenses', expenseForm(['branch_id' => $this->branch->id]));

    expect(Expense::sole()->branch_id)->toBe($this->hargeisa->id);
    $this->actingAs($clerk)->get('/expenses/create')->assertOk()->assertDontSee('name="branch_id"', false)->assertSee('Hargeisa');
});

it('records an expense once, however many times the form is sent', function () {
    $clerk = bookkeeper($this->branch);
    $form = expenseForm();

    $this->actingAs($clerk)->post('/expenses', $form);
    $this->actingAs($clerk)->post('/expenses', $form);

    expect(Expense::count())->toBe(1)->and(companyBalance($this->company))->toBe(75000);
});

it('converts a foreign-currency expense to dollars and keeps what was typed', function () {
    Currency::query()->firstOrCreate(['code' => 'USD'], ['name' => 'US Dollar', 'is_active' => true]);
    Currency::query()->firstOrCreate(['code' => 'EUR'], ['name' => 'Euro', 'is_active' => true])->update(['manual_rate' => '1.08000000']);

    $expense = addExpense(bookkeeper($this->branch), ['currency' => 'EUR', 'amount_cents' => 10000]);

    expect($expense->amount_cents)->toBe(10800)
        ->and($expense->original_currency)->toBe('EUR')
        ->and($expense->original_amount_cents)->toBe(10000)
        ->and((float) $expense->rate)->toBe(1.08)
        ->and(companyBalance($this->company))->toBe(100000 - 10800);
});

it('refuses an expense the account cannot cover, and posts nothing', function () {
    $poor = expenseCompany(1000, 'Petty cash');

    $this->actingAs(bookkeeper($this->branch))
        ->post('/expenses', expenseForm(['company_account_id' => $poor->id, 'amount' => '50']))
        ->assertSessionHasErrors('company_account_id');

    expect(Expense::count())->toBe(0)
        ->and(companyBalance($poor))->toBe(1000)
        ->and(expensesBalance())->toBe(0);
});

it('validates the form', function () {
    $clerk = bookkeeper($this->branch);

    $this->actingAs($clerk)->post('/expenses', expenseForm(['expense_date' => today()->addDay()->toDateString()]))->assertSessionHasErrors('expense_date');
    $this->actingAs($clerk)->post('/expenses', expenseForm(['amount' => '0']))->assertSessionHasErrors('amount');
    $this->actingAs($clerk)->post('/expenses', expenseForm(['amount' => '-5']))->assertSessionHasErrors('amount');
    $this->actingAs($clerk)->post('/expenses', array_diff_key(expenseForm(), ['company_account_id' => 1]))->assertSessionHasErrors('company_account_id');

    expect(Expense::count())->toBe(0);
});

it('needs the create permission', function () {
    $viewer = bookkeeper($this->branch, ['expenses.view_own']);

    $this->actingAs($viewer)->get('/expenses/create')->assertForbidden();
    $this->actingAs($viewer)->post('/expenses', expenseForm())->assertForbidden();
    $this->actingAs(H_sendingAgentForExpenses())->get('/expenses')->assertForbidden();
});

function H_sendingAgentForExpenses(): User
{
    $u = User::factory()->create(['type' => UserType::SendingAgent]);
    $u->assignRole('sending-agent');

    return $u;
}

// --- Editing and voiding ----------------------------------------------------------------

it('lets details change but never the amount or account', function () {
    $admin = makeAdmin();
    $expense = addExpense($admin);

    $this->actingAs($admin)->put("/expenses/{$expense->id}", [
        'expense_type_id' => ExpenseType::where('name', 'Running Cost')->value('id'),
        'expense_date' => today()->toDateString(), 'payee' => 'New landlord', 'reference' => 'INV-9', 'description' => 'March',
        'amount' => '9999', 'company_account_id' => 999,
    ])->assertRedirect();

    $expense->refresh();

    expect($expense->payee)->toBe('New landlord')->and($expense->type->name)->toBe('Running Cost')
        ->and($expense->amount_cents)->toBe(25000)->and($expense->company_account_id)->toBe($this->company->id);
});

it('voids an expense by reversing it in the ledger', function () {
    $admin = makeAdmin();
    $expense = addExpense($admin);

    $this->actingAs($admin)->post("/expenses/{$expense->id}/void", ['reason' => 'Entered twice'])->assertRedirect();

    $expense->refresh();

    expect($expense->status)->toBe(ExpenseStatus::Voided)
        ->and($expense->void_reason)->toBe('Entered twice')
        ->and($expense->voided_by)->toBe($admin->id)
        ->and(companyBalance($this->company))->toBe(100000)
        ->and(expensesBalance())->toBe(0)
        ->and(JournalLine::sum('amount_cents'))->toBe(0)
        ->and(JournalLine::count())->toBeGreaterThan(2); // history kept, not deleted

    // Not twice, not edited afterwards, reason mandatory.
    $this->actingAs($admin)->post("/expenses/{$expense->id}/void", ['reason' => 'again'])->assertForbidden();
    $this->actingAs($admin)->get("/expenses/{$expense->id}/edit")->assertForbidden();
    expect(companyBalance($this->company))->toBe(100000);

    $this->artisan('hawala:verify-ledger')->assertSuccessful();
});

it('wants a reason to void and the void permission', function () {
    $expense = addExpense(makeAdmin());
    $clerk = bookkeeper($this->branch, ['expenses.view_all', 'expenses.create']);

    $this->actingAs($clerk)->post("/expenses/{$expense->id}/void", ['reason' => 'x'])->assertForbidden();
    $this->actingAs(makeAdmin())->post("/expenses/{$expense->id}/void", [])->assertSessionHasErrors('reason');

    expect($expense->fresh()->status)->toBe(ExpenseStatus::Posted);
});

// --- Who sees what ----------------------------------------------------------------------

it('scopes the list to own, branch or all', function () {
    $mine = bookkeeper($this->branch);
    $colleague = bookkeeper($this->branch);
    $elsewhere = bookkeeper($this->hargeisa);

    addExpense($mine, ['payee' => 'MINE-PAYEE']);
    addExpense($colleague, ['payee' => 'COLLEAGUE-PAYEE']);
    addExpense($elsewhere, ['payee' => 'ELSEWHERE-PAYEE']);

    $this->actingAs($mine)->get('/expenses')->assertOk()
        ->assertSee('MINE-PAYEE')->assertDontSee('COLLEAGUE-PAYEE')->assertDontSee('ELSEWHERE-PAYEE');

    $manager = bookkeeper($this->branch, ['expenses.view_own', 'expenses.view_branch']);
    $this->actingAs($manager)->get('/expenses')->assertOk()
        ->assertSee('MINE-PAYEE')->assertSee('COLLEAGUE-PAYEE')->assertDontSee('ELSEWHERE-PAYEE');

    $this->actingAs(makeAdmin())->get('/expenses')->assertOk()
        ->assertSee('MINE-PAYEE')->assertSee('COLLEAGUE-PAYEE')->assertSee('ELSEWHERE-PAYEE');
});

it('will not show someone else’s expense', function () {
    $other = addExpense(bookkeeper($this->hargeisa));

    $this->actingAs(bookkeeper($this->branch))->get("/expenses/{$other->id}")->assertForbidden();
    $this->actingAs(makeAdmin())->get("/expenses/{$other->id}")->assertOk();
});

it('filters the list', function () {
    $admin = makeAdmin();
    addExpense($admin, ['payee' => 'RENT-PAYEE']);
    addExpense($admin, ['payee' => 'PAYROLL-PAYEE'], type: ExpenseType::where('name', 'Staff Payroll')->first());

    $this->actingAs($admin)->get('/expenses?type='.ExpenseType::where('name', 'Staff Payroll')->value('id'))
        ->assertSee('PAYROLL-PAYEE')->assertDontSee('RENT-PAYEE');
    $this->actingAs($admin)->get('/expenses?search=RENT')->assertSee('RENT-PAYEE')->assertDontSee('PAYROLL-PAYEE');
});

it('shows Expenses in the menu only to those who may open it', function () {
    $this->actingAs(makeAdmin())->get('/dashboard')->assertSee('href="'.route('expenses.index').'"', false);
    $this->actingAs(H_sendingAgentForExpenses())->get('/dashboard')->assertDontSee('href="'.route('expenses.index').'"', false);
});

// --- Reports and profit ---------------------------------------------------------------------

it('reports expenses, leaving out voided ones, and by type', function () {
    $admin = makeAdmin();
    addExpense($admin, ['amount_cents' => 30000]);
    addExpense($admin, ['amount_cents' => 10000], type: ExpenseType::where('name', 'Staff Payroll')->first());
    $voided = addExpense($admin, ['amount_cents' => 5000]);
    app(ExpenseService::class)->void($voided, $admin, 'oops');

    $detail = app(ReportRegistry::class)->find('expenses');
    $rows = $detail->rows(expFilters(), $admin);

    expect($rows)->toHaveCount(2)->and($detail->totals($rows)['amount'])->toBe(40000);

    $byType = app(ReportRegistry::class)->find('expenses-by-type')->rows(expFilters(), $admin);

    expect($byType->pluck('type')->all())->toBe(['Office Rent', 'Staff Payroll'])
        ->and($byType[0]['amount'])->toBe(30000)
        ->and($byType[0]['share'])->toBe('75.0%');

    expect($detail->rows(expFilters(['type' => ExpenseType::where('name', 'Staff Payroll')->value('id')]), $admin))->toHaveCount(1);
});

it('scopes the expense reports like the list', function () {
    addExpense(bookkeeper($this->hargeisa), ['amount_cents' => 7000]);
    $mine = bookkeeper($this->branch, ['expenses.view_own', 'expenses.create', 'reports.expenses']);
    addExpense($mine, ['amount_cents' => 3000]);

    $rows = app(ReportRegistry::class)->find('expenses')->rows(expFilters(), $mine);

    expect($rows)->toHaveCount(1)->and($rows[0]['amount'])->toBe(3000);
    $this->actingAs($mine)->get('/reports/expenses')->assertOk();
});

it('takes expenses off the profit', function () {
    $ledger = app(LedgerService::class);
    $admin = makeAdmin();

    // $500 of fee income, then $200 of expenses.
    $ledger->post(JournalType::ManualAdjustment, [
        [$ledger->accountFor($this->company, LedgerAccountType::Company), 50000],
        [$ledger->system(LedgerAccountType::FeeIncome), -50000],
    ], null, $admin);
    addExpense($admin, ['amount_cents' => 20000]);

    $row = app(ReportRegistry::class)->find('profit')->rows(
        new ReportFilters(CarbonImmutable::now()->subDay(), CarbonImmutable::now()->addDay(), groupBy: 'day'), $admin
    )->first();

    expect($row['fee_income'])->toBe(50000)->and($row['expenses'])->toBe(20000)// The opening-capital adjustment from setUp also counts (known: see BUILD.md §16 note).
        ->and($row['net'])->toBe(50000 - $row['adjustments'] - 20000);
});
