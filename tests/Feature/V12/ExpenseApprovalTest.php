<?php

use App\Enums\ExpenseStatus;
use App\Enums\JournalType;
use App\Enums\LedgerAccountType;
use App\Enums\UserType;
use App\Exceptions\Balances\SelfApprovalException;
use App\Exceptions\Expenses\ExpenseStateException;
use App\Exceptions\Ledger\InsufficientBalanceException;
use App\Models\Branch;
use App\Models\CompanyAccount;
use App\Models\Currency;
use App\Models\Expense;
use App\Models\ExpenseType;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\User;
use App\Notifications\ExpenseDecided;
use App\Notifications\ExpenseSubmitted;
use App\Services\Expenses\ExpenseService;
use App\Services\Ledger\LedgerService;
use App\Support\Brand;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Permission;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->branch = Branch::create(['name' => 'Mogadishu', 'city' => 'Mogadishu', 'is_payout_point' => true, 'is_active' => true]);
    Currency::query()->firstOrCreate(['code' => 'USD'], ['name' => 'US Dollar', 'is_active' => true]);
    $this->company = v12Company(100_000); // $1,000
    $this->service = app(ExpenseService::class);
});

function v12Company(int $cents, string $name = 'Mogadishu Office'): CompanyAccount
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

function v12Balance(CompanyAccount $a): int
{
    return app(LedgerService::class)->accountFor($a, LedgerAccountType::Company)->fresh()->balance_cents;
}

function v12Expenses(): int
{
    return app(LedgerService::class)->system(LedgerAccountType::Expenses)->fresh()->balance_cents;
}

/** Someone who records expenses: no approval rights. */
function v12Clerk(): User
{
    $user = User::factory()->create(['branch_id' => Branch::query()->first()->id]);
    $user->givePermissionTo(['expenses.view_own', 'expenses.create', 'expenses.edit']);

    return $user;
}

/** Someone who approves what others record. */
function v12Approver(array $extra = []): User
{
    $user = User::factory()->create(['branch_id' => Branch::query()->first()->id]);
    $user->givePermissionTo(['expenses.view_own', 'expenses.view_all', 'expenses.approve', ...$extra]);

    return $user;
}

function v12Pending(?User $by = null, array $overrides = [], ?CompanyAccount $from = null): Expense
{
    return app(ExpenseService::class)->record(
        $by ?? v12Clerk(),
        ExpenseType::where('name', 'Office Rent')->first(),
        $from ?? test()->company,
        array_merge(['amount_cents' => 25_000, 'expense_date' => today()->toDateString(), 'payee' => 'Landlord'], $overrides),
        (string) Str::uuid(),
    );
}

// --- Approving --------------------------------------------------------------

it('pays the expense out of the account when someone else approves it', function () {
    $clerk = v12Clerk();
    $approver = v12Approver();
    $expense = v12Pending($clerk);

    $this->actingAs($approver)->post("/expenses/{$expense->id}/approve", ['reason' => 'Matches the invoice'])->assertRedirect();

    $expense->refresh();

    expect($expense->status)->toBe(ExpenseStatus::Approved)
        ->and($expense->decided_by)->toBe($approver->id)
        ->and($expense->decided_at)->not->toBeNull()
        ->and($expense->decision_reason)->toBe('Matches the invoice')
        ->and($expense->journal_entry_id)->not->toBeNull()
        ->and(v12Balance($this->company))->toBe(75_000)
        ->and(v12Expenses())->toBe(25_000)
        ->and(JournalLine::sum('amount_cents'))->toBe(0);

    // The ledger entry names both people.
    $entry = JournalEntry::find($expense->journal_entry_id);
    expect($entry->created_by)->toBe($clerk->id)->and($entry->approved_by)->toBe($approver->id);

    $this->artisan('hawala:verify-ledger')->assertSuccessful();
});

it('takes the reason as optional', function () {
    $expense = v12Pending();

    $this->actingAs(v12Approver())->post("/expenses/{$expense->id}/approve")->assertRedirect();

    expect($expense->fresh()->status)->toBe(ExpenseStatus::Approved)->and($expense->fresh()->decision_reason)->toBeNull();
});

it('never lets the person who recorded an expense approve it', function () {
    $creator = v12Approver(['expenses.create']);
    $expense = v12Pending($creator);

    // Service: refused on the locked row.
    expect(fn () => $this->service->approve($expense, $creator))->toThrow(SelfApprovalException::class);
    expect(fn () => $this->service->reject($expense, $creator))->toThrow(SelfApprovalException::class);

    // HTTP: the route is open to them (they hold the permission) but the policy says no.
    $this->actingAs($creator)->post("/expenses/{$expense->id}/approve")->assertForbidden();
    $this->actingAs($creator)->post("/expenses/{$expense->id}/reject")->assertForbidden();

    expect($expense->fresh()->status)->toBe(ExpenseStatus::Pending)
        ->and(v12Balance($this->company))->toBe(100_000);
});

it('applies the same rule to a super-admin', function () {
    $super = makeSuperAdmin();
    $expense = v12Pending($super);

    $this->actingAs($super)->post("/expenses/{$expense->id}/approve")->assertForbidden();
    expect(fn () => $this->service->approve($expense, $super))->toThrow(SelfApprovalException::class);

    // …and shows no Approve button on their own expense.
    $this->actingAs($super)->get("/expenses/{$expense->id}")->assertOk()
        ->assertDontSee('approve-expense', false)
        ->assertSee('someone else has to approve it');

    // A different super-admin can.
    $this->actingAs(makeSuperAdmin())->post("/expenses/{$expense->id}/approve")->assertRedirect();
    expect($expense->fresh()->status)->toBe(ExpenseStatus::Approved);
});

it('needs the approve permission', function () {
    $expense = v12Pending();
    $viewer = User::factory()->create(['branch_id' => $this->branch->id]);
    $viewer->givePermissionTo(['expenses.view_all', 'expenses.create']);

    $this->actingAs($viewer)->post("/expenses/{$expense->id}/approve")->assertForbidden();
    $this->actingAs($viewer)->post("/expenses/{$expense->id}/reject")->assertForbidden();
    $this->actingAs($viewer)->post("/expenses/{$expense->id}/reopen")->assertForbidden();

    expect($expense->fresh()->status)->toBe(ExpenseStatus::Pending);
});

it('stays pending when the account cannot cover it, and says so', function () {
    $poor = v12Company(1_000, 'Petty cash');
    $expense = v12Pending(null, ['amount_cents' => 5_000], $poor);

    $this->actingAs(v12Approver())->post("/expenses/{$expense->id}/approve")
        ->assertSessionHasErrors('status');

    expect($expense->fresh()->status)->toBe(ExpenseStatus::Pending)
        ->and($expense->fresh()->journal_entry_id)->toBeNull()
        ->and(v12Balance($poor))->toBe(1_000)
        ->and(v12Expenses())->toBe(0);

    expect(fn () => $this->service->approve($expense, v12Approver()))->toThrow(InsufficientBalanceException::class);
});

it('pays an expense once even if approval is sent twice', function () {
    $expense = v12Pending();
    $approver = v12Approver();

    $this->service->approve($expense, $approver);

    expect(fn () => $this->service->approve($expense, v12Approver()))->toThrow(ExpenseStateException::class);
    // A double click gets a clear message, not a 403 page.
    $this->actingAs($approver)->post("/expenses/{$expense->id}/approve")->assertSessionHasErrors('status');

    expect(v12Balance($this->company))->toBe(75_000);
});

// --- Rejecting and locking --------------------------------------------------

it('rejects without touching money and records who, when and why', function () {
    $expense = v12Pending();
    $approver = v12Approver();

    $this->actingAs($approver)->post("/expenses/{$expense->id}/reject", ['reason' => 'No invoice'])->assertRedirect();

    $expense->refresh();

    expect($expense->status)->toBe(ExpenseStatus::Rejected)
        ->and($expense->decided_by)->toBe($approver->id)
        ->and($expense->decision_reason)->toBe('No invoice')
        ->and($expense->journal_entry_id)->toBeNull()
        ->and(v12Balance($this->company))->toBe(100_000)
        ->and(JournalLine::count())->toBe(2);
});

it('locks an expense once it is approved or rejected', function () {
    $clerk = v12Clerk();
    $admin = makeAdmin();
    $approved = v12Pending($clerk);
    $rejected = v12Pending($clerk);
    $this->service->approve($approved, $admin);
    $this->service->reject($rejected, $admin);

    foreach ([$approved, $rejected] as $expense) {
        $this->actingAs($clerk)->get("/expenses/{$expense->id}/edit")->assertForbidden();
        $this->actingAs($clerk)->put("/expenses/{$expense->id}", ['amount' => '1'])->assertForbidden();
        // Even an approver cannot decide it again: a clear message, no change.
        $this->actingAs($admin)->post("/expenses/{$expense->id}/approve")->assertSessionHasErrors('status');
        $this->actingAs($admin)->post("/expenses/{$expense->id}/reject")->assertSessionHasErrors('status');
    }

    expect($approved->fresh()->status)->toBe(ExpenseStatus::Approved)->and($rejected->fresh()->status)->toBe(ExpenseStatus::Rejected);

    expect(fn () => $this->service->update($approved, [
        'expense_type_id' => 1, 'company_account_id' => $this->company->id, 'currency' => 'USD',
        'amount_cents' => 1, 'expense_date' => today()->toDateString(),
    ]))->toThrow(ExpenseStateException::class);

    expect($approved->fresh()->amount_cents)->toBe(25_000);
});

// --- Return to pending ------------------------------------------------------

it('returns an approved expense to pending by reversing the ledger', function () {
    $clerk = v12Clerk();
    $approver = v12Approver();
    $expense = v12Pending($clerk);
    $this->service->approve($expense, $approver);

    $this->actingAs($approver)->post("/expenses/{$expense->id}/reopen", ['reason' => 'Wrong account'])->assertRedirect();

    $expense->refresh();

    expect($expense->status)->toBe(ExpenseStatus::Pending)
        ->and($expense->decided_by)->toBeNull()
        ->and($expense->decided_at)->toBeNull()
        ->and($expense->decision_reason)->toBeNull()
        ->and($expense->journal_entry_id)->toBeNull()
        ->and(v12Balance($this->company))->toBe(100_000)
        ->and(v12Expenses())->toBe(0)
        ->and(JournalLine::sum('amount_cents'))->toBe(0)
        ->and(JournalEntry::where('type', JournalType::ExpenseVoid)->count())->toBe(1)
        ->and(JournalEntry::where('type', JournalType::Expense)->count())->toBe(1); // history kept

    // Editable again, then approvable again.
    $this->actingAs($clerk)->get("/expenses/{$expense->id}/edit")->assertOk();
    $this->service->approve($expense, $approver);
    expect(v12Balance($this->company))->toBe(75_000);

    $this->artisan('hawala:verify-ledger')->assertSuccessful();
});

it('returns a rejected expense to pending without any ledger entry', function () {
    $expense = v12Pending();
    $this->service->reject($expense, makeAdmin());

    $this->actingAs(v12Approver())->post("/expenses/{$expense->id}/reopen")->assertRedirect();

    expect($expense->fresh()->status)->toBe(ExpenseStatus::Pending)
        ->and(JournalEntry::where('type', JournalType::ExpenseVoid)->count())->toBe(0);
});

it('does not let the creator return their own expense to pending', function () {
    $creator = v12Approver(['expenses.create']);
    $expense = v12Pending($creator);
    $this->service->approve($expense, makeAdmin());

    $this->actingAs($creator)->post("/expenses/{$expense->id}/reopen")->assertForbidden();
    expect(fn () => $this->service->returnToPending($expense, $creator))->toThrow(SelfApprovalException::class);
    expect($expense->fresh()->status)->toBe(ExpenseStatus::Approved);
});

it('refuses to return a pending expense to pending', function () {
    $expense = v12Pending();

    expect(fn () => $this->service->returnToPending($expense, makeAdmin()))->toThrow(ExpenseStateException::class);
});

// --- Deleting ---------------------------------------------------------------

it('hard-deletes a pending expense', function () {
    $admin = makeAdmin();
    $expense = v12Pending();

    $this->actingAs($admin)->delete("/expenses/{$expense->id}")->assertRedirect('/expenses');

    expect(Expense::count())->toBe(0);
    // The deletion is on the audit log with who did it.
    expect(Activity::where('event', 'deleted')->where('causer_id', $admin->id)->count())->toBe(1);
});

it('hard-deletes a rejected expense', function () {
    $admin = makeAdmin();
    $expense = v12Pending();
    $this->service->reject($expense, v12Approver());

    $this->actingAs($admin)->delete("/expenses/{$expense->id}")->assertRedirect();

    expect(Expense::count())->toBe(0);
});

it('refuses to delete an approved expense until it is returned to pending', function () {
    $admin = makeAdmin();
    $expense = v12Pending(v12Clerk());
    $this->service->approve($expense, v12Approver());

    $this->actingAs($admin)->delete("/expenses/{$expense->id}")->assertSessionHasErrors('status');
    expect(fn () => $this->service->delete($expense, $admin))->toThrow(ExpenseStateException::class);
    expect(Expense::count())->toBe(1)->and(v12Balance($this->company))->toBe(75_000);

    // Return it, then it can go.
    $this->service->returnToPending($expense, $admin);
    $this->actingAs($admin)->delete("/expenses/{$expense->id}")->assertRedirect();

    expect(Expense::count())->toBe(0)
        ->and(v12Balance($this->company))->toBe(100_000)
        // Ledger rows are insert-only: the posting and its reversal are still there.
        ->and(JournalEntry::whereIn('type', [JournalType::Expense, JournalType::ExpenseVoid])->count())->toBe(2);

    $this->artisan('hawala:verify-ledger')->assertSuccessful();
});

it('needs the delete permission', function () {
    $expense = v12Pending();

    $this->actingAs(v12Approver())->delete("/expenses/{$expense->id}")->assertForbidden();
    expect(Expense::count())->toBe(1);
});

it('shows the delete icon switched off for approved expenses', function () {
    $admin = makeAdmin();
    $pending = v12Pending(null, ['payee' => 'PENDING-ONE']);
    $approved = v12Pending(null, ['payee' => 'APPROVED-ONE']);
    $this->service->approve($approved, $admin);

    $html = $this->actingAs($admin)->get('/expenses')->assertOk()->getContent();

    expect($html)->toContain(route('expenses.destroy', $pending))
        ->not->toContain('action="'.route('expenses.destroy', $approved).'"')
        ->toContain('Return it to pending before deleting')
        ->toContain(route('expenses.print', $approved))
        ->toContain(route('expenses.show', $approved));
});

// --- Numbers, history, attachment, voucher ----------------------------------

it('numbers expenses EX-0001, EX-0002, …', function () {
    expect([v12Pending()->number, v12Pending()->number, v12Pending()->number])->toBe(['EX-0001', 'EX-0002', 'EX-0003']);
});

it('shows approval history with who, when and why', function () {
    $clerk = v12Clerk();
    $approver = v12Approver();
    $expense = v12Pending($clerk);

    $this->actingAs($clerk)->get("/expenses/{$expense->id}")->assertOk();
    $this->service->approve($expense, $approver, 'Checked against the contract');
    $this->service->returnToPending($expense, $approver, 'Posted to the wrong account');

    $this->actingAs($approver)->get("/expenses/{$expense->id}")->assertOk()
        ->assertSee('History')
        ->assertSee('Approved')
        ->assertSee('Checked against the contract')
        ->assertSee('Returned to pending')
        ->assertSee('Posted to the wrong account')
        ->assertSee($approver->name);
});

it('stores an attachment privately and serves it only to people who may see the expense', function () {
    Storage::fake('local');
    $clerk = v12Clerk();

    $this->actingAs($clerk)->post('/expenses', [
        'idempotency_key' => (string) Str::uuid(),
        'expense_type_id' => ExpenseType::where('name', 'Office Rent')->value('id'),
        'company_account_id' => $this->company->id,
        'currency' => 'USD', 'amount' => '250', 'expense_date' => today()->toDateString(),
        'attachment' => UploadedFile::fake()->create('invoice.pdf', 200, 'application/pdf'),
    ])->assertRedirect();

    $expense = Expense::sole();

    expect($expense->attachment_name)->toBe('invoice.pdf');
    Storage::disk('local')->assertExists($expense->attachment_path);

    $this->actingAs($clerk)->get("/expenses/{$expense->id}/attachment")->assertOk();
    $this->actingAs(v12Approver())->get("/expenses/{$expense->id}")->assertOk()->assertSee('invoice.pdf');

    // Someone with no right to the expense gets nothing.
    $stranger = v12Clerk();
    $this->actingAs($stranger)->get("/expenses/{$expense->id}/attachment")->assertForbidden();

    // Deleting the expense deletes the file.
    $path = $expense->attachment_path;
    $this->service->delete($expense, makeAdmin());
    Storage::disk('local')->assertMissing($path);
});

it('only accepts a pdf or an image up to 5 MB as an attachment', function () {
    Storage::fake('local');
    $clerk = v12Clerk();
    $form = fn ($file) => [
        'idempotency_key' => (string) Str::uuid(),
        'expense_type_id' => ExpenseType::where('name', 'Office Rent')->value('id'),
        'company_account_id' => $this->company->id,
        'currency' => 'USD', 'amount' => '10', 'expense_date' => today()->toDateString(),
        'attachment' => $file,
    ];

    $this->actingAs($clerk)->post('/expenses', $form(UploadedFile::fake()->create('virus.exe', 10)))->assertSessionHasErrors('attachment');
    $this->actingAs($clerk)->post('/expenses', $form(UploadedFile::fake()->create('big.pdf', 6000, 'application/pdf')))->assertSessionHasErrors('attachment');
    $this->actingAs($clerk)->post('/expenses', $form(UploadedFile::fake()->image('receipt.jpg')))->assertSessionHasNoErrors();

    expect(Expense::count())->toBe(1);
});

it('prints a voucher PDF for anyone who may view the expense', function () {
    $expense = v12Pending();
    $this->service->approve($expense, makeAdmin(), 'ok');

    $response = $this->actingAs(v12Approver())->get("/expenses/{$expense->id}/print")->assertOk();

    expect($response->headers->get('content-type'))->toContain('application/pdf')
        ->and(substr($response->getContent(), 0, 4))->toBe('%PDF');

    $this->actingAs(v12Clerk())->get("/expenses/{$expense->id}/print")->assertForbidden();
});

it('renders the voucher with logo, number, status and signature lines', function () {
    $expense = v12Pending();
    $expense->load(['type', 'branch', 'companyAccount', 'createdBy', 'decidedBy']);

    $html = view('expenses.voucher', [
        'expense' => $expense, 'companyName' => 'Blue Star', 'logo' => Brand::logoDataUri(), 'timezone' => 'Africa/Mogadishu',
    ])->render();

    expect($html)->toContain($expense->number)
        ->toContain('data:image/png;base64')
        ->toContain('Expense voucher')
        ->toContain('Not approved yet')
        ->toContain('Prepared by')->toContain('Approved by')->toContain('Received by');
});

// --- Notifications ----------------------------------------------------------

it('tells approvers (not the creator) about a new expense, and the creator about the decision', function () {
    Notification::fake();
    $creator = v12Approver(['expenses.create']);
    $other = v12Approver();
    $nobody = v12Clerk();

    $expense = v12Pending($creator);

    Notification::assertSentTo($other, ExpenseSubmitted::class);
    Notification::assertNotSentTo($creator, ExpenseSubmitted::class);
    Notification::assertNotSentTo($nobody, ExpenseSubmitted::class);

    $this->service->approve($expense, $other, 'fine');

    Notification::assertSentTo($creator, ExpenseDecided::class, fn ($n) => str_contains($n->toDatabase($creator)['message'], 'approved'));
});

// --- Permissions ------------------------------------------------------------

it('gives the admin role approve and delete, and no longer has a void permission', function () {
    $admin = makeAdmin();

    expect($admin->can('expenses.approve'))->toBeTrue()
        ->and($admin->can('expenses.delete'))->toBeTrue()
        ->and(Permission::where('name', 'expenses.void')->exists())->toBeFalse();
});

it('keeps the sending agent role away from expenses', function () {
    $agent = User::factory()->create(['type' => UserType::SendingAgent]);
    $agent->assignRole('sending-agent');

    expect($agent->can('expenses.approve'))->toBeFalse();
    $this->actingAs($agent)->get('/expenses')->assertForbidden();
});

// --- Totals on the list -----------------------------------------------------

it('adds up approved and pending separately on the list', function () {
    $admin = makeAdmin();
    v12Pending(null, ['amount_cents' => 10_000]);
    $approved = v12Pending(null, ['amount_cents' => 30_000]);
    $this->service->approve($approved, $admin);
    $rejected = v12Pending(null, ['amount_cents' => 70_000]);
    $this->service->reject($rejected, $admin);

    $this->actingAs($admin)->get('/expenses')->assertOk()
        ->assertSee('Total approved')->assertSee('$300.00')
        ->assertSee('Awaiting approval')->assertSee('$100.00');
});
