<?php

use App\Enums\ExpenseStatus;
use App\Enums\JournalType;
use App\Enums\LedgerAccountType;
use App\Http\Controllers\ReportsController;
use App\Models\Branch;
use App\Models\CompanyAccount;
use App\Models\Currency;
use App\Models\Expense;
use App\Models\ExpenseType;
use App\Models\Transfer;
use App\Models\User;
use App\Reports\ReportFilters;
use App\Reports\ReportRegistry;
use App\Services\Expenses\ExpenseService;
use App\Services\Ledger\LedgerService;
use App\Services\Transfers\TransferService;
use App\Support\Brand;
use App\Support\Settings;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\Feature\Transfers\TransferTestHelpers as H;

beforeEach(function () {
    // dompdf is memory-hungry and the test process keeps what earlier tests built.
    ini_set('memory_limit', '1G');
    gc_collect_cycles();
    Notification::fake();
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->branch = H::seedReferenceData();
    Currency::query()->firstOrCreate(['code' => 'USD'], ['name' => 'US Dollar', 'is_active' => true]);
});

function p3Company(int $cents = 1_000_000): CompanyAccount
{
    $ledger = app(LedgerService::class);
    $account = CompanyAccount::create(['name' => 'Mogadishu Office', 'location' => 'somalia', 'is_active' => true]);
    $ledger->post(
        JournalType::ManualAdjustment,
        [[$ledger->accountFor($account, LedgerAccountType::Company), $cents], [$ledger->system(LedgerAccountType::Adjustments), -$cents]],
        null,
        User::factory()->create(),
    );

    return $account;
}

function p3Expense(CompanyAccount $from, string $status, int $cents = 10_000, string $payee = 'Landlord'): Expense
{
    $creator = User::factory()->create(['branch_id' => Branch::query()->first()->id]);
    $expense = app(ExpenseService::class)->record(
        $creator,
        ExpenseType::where('name', 'Office Rent')->first(),
        $from,
        ['amount_cents' => $cents, 'expense_date' => today()->toDateString(), 'payee' => $payee],
        (string) Str::uuid(),
    );

    return match ($status) {
        'approved' => app(ExpenseService::class)->approve($expense, makeAdmin()),
        'rejected' => app(ExpenseService::class)->reject($expense, makeAdmin()),
        default => $expense,
    };
}

// --- Every report has Print and PDF ---------------------------------------

it('puts Print and Download PDF on every report page', function () {
    $super = makeSuperAdmin();

    foreach (app(ReportRegistry::class)->all() as $report) {
        $this->actingAs($super)->get("/reports/{$report->key()}")->assertOk()
            ->assertSee(route('reports.print', $report->key()), false)
            ->assertSee(route('reports.pdf', $report->key()), false)
            ->assertSee('Print')
            ->assertSee('Download PDF');
    }
});

it('renders every report in the one shared print layout', function () {
    $super = makeSuperAdmin();

    foreach (app(ReportRegistry::class)->all() as $report) {
        $html = $this->actingAs($super)->get("/reports/{$report->key()}/print")->assertOk()->getContent();

        expect($html)->toContain(e($report->title()))
            ->toContain('data:image/png;base64')          // the logo
            ->toContain('Generated:')->toContain($super->name)
            ->toContain('Period:')                          // the filters used
            ->toContain('display: table-header-group')      // header repeats on every page
            ->toContain('counter(page)')                    // page numbers in the browser
            ->toContain('window.print()');
    }
});

it('downloads a real PDF for every report', function () {
    $super = makeSuperAdmin();

    foreach (app(ReportRegistry::class)->all() as $report) {
        $response = $this->actingAs($super)->get("/reports/{$report->key()}/pdf")->assertOk();

        expect($response->headers->get('content-type'))->toContain('application/pdf')
            ->and($response->headers->get('content-disposition'))->toContain("{$report->key()}-")->toContain('.pdf')
            ->and(substr($response->getContent(), 0, 4))->toBe('%PDF');
    }
});

it('hides the menus and buttons when a report page is printed', function () {
    $html = $this->actingAs(makeSuperAdmin())->get('/reports/expenses')->assertOk()->getContent();

    expect($html)->toMatch('/<aside[^>]*print:hidden/s')
        ->and($html)->toContain('print:pl-0')
        ->and($html)->toMatch('/sticky top-0 z-30 print:hidden/');
});

it('keeps the print and PDF to people who may run the report', function () {
    $agent = H::sendingAgent(fundCents: 0);

    $this->actingAs($agent)->get('/reports/profit/print')->assertForbidden();
    $this->actingAs($agent)->get('/reports/profit/pdf')->assertForbidden();
    $this->actingAs(makeSuperAdmin())->get('/reports/nope/print')->assertNotFound();
    $this->actingAs(makeSuperAdmin())->get('/reports/nope/pdf')->assertNotFound();
});

it('says which filters were used in the header', function () {
    $admin = makeAdmin();
    $account = p3Company();
    p3Expense($account, 'approved');

    $html = $this->actingAs($admin)->get('/reports/expenses/print?branch_id='.$this->branch->id.'&status=pending&from=2026-01-01&to=2026-12-31')->getContent();

    expect($html)->toContain('2026-01-01 to 2026-12-31')
        ->toContain('Branch:')->toContain($this->branch->name)
        ->toContain('Status:')->toContain('Pending')
        ->toContain('Category:')->toContain('All categories');
});

it('refuses a PDF too big for shared hosting and points to CSV', function () {
    config(['hawala.reports.pdf_max_rows' => 1]);
    $account = p3Company();
    p3Expense($account, 'approved');
    p3Expense($account, 'approved');

    $this->actingAs(makeAdmin())->from('/reports/expenses')->get('/reports/expenses/pdf')
        ->assertRedirect('/reports/expenses')
        ->assertSessionHasErrors('pdf');

    // Print has no such limit.
    $this->actingAs(makeAdmin())->get('/reports/expenses/print')->assertOk();
});

it('repeats the header and numbers the pages on a long PDF', function () {
    if (trim((string) shell_exec('command -v pdftotext')) === '') {
        $this->markTestSkipped('pdftotext is not installed.');
    }

    $account = p3Company();
    $creator = User::factory()->create(['branch_id' => $this->branch->id]);
    $type = ExpenseType::first();

    foreach (range(1, 140) as $i) {
        Expense::create([
            'number' => 'EX-'.str_pad((string) $i, 4, '0', STR_PAD_LEFT), 'expense_type_id' => $type->id, 'branch_id' => $this->branch->id,
            'company_account_id' => $account->id, 'amount_cents' => 1000, 'original_currency' => 'USD', 'original_amount_cents' => 1000,
            'expense_date' => today(), 'payee' => "Payee {$i}", 'status' => ExpenseStatus::Approved, 'created_by' => $creator->id,
            'idempotency_key' => (string) Str::uuid(),
        ]);
    }

    $pdf = $this->actingAs(makeAdmin())->get('/reports/expenses/pdf')->assertOk()->getContent();
    $file = tempnam(sys_get_temp_dir(), 'rpt').'.pdf';
    file_put_contents($file, $pdf);
    $text = (string) shell_exec('pdftotext -layout '.escapeshellarg($file).' - 2>/dev/null');
    @unlink($file);

    preg_match_all('/Page (\d+) \/ (\d+)/', $text, $pages);
    $count = (int) max($pages[2] ?: [0]);

    expect($count)->toBeGreaterThan(1)
        ->and(count($pages[0]))->toBe($count)
        // The title and the column headings appear once per page.
        ->and(substr_count($text, 'Generated:'))->toBe($count)
        ->and(substr_count($text, 'PAID FROM'))->toBe($count);
});

// --- Expenses report ------------------------------------------------------

it('reports approved expenses by default and lets the status filter widen it', function () {
    $account = p3Company();
    p3Expense($account, 'approved', 30_000, 'APPROVED-PAYEE');
    p3Expense($account, 'pending', 10_000, 'PENDING-PAYEE');
    p3Expense($account, 'rejected', 5_000, 'REJECTED-PAYEE');
    $admin = makeAdmin();

    $default = $this->actingAs($admin)->get('/reports/expenses')->assertOk();
    $default->assertSee('APPROVED-PAYEE')->assertDontSee('PENDING-PAYEE')->assertDontSee('REJECTED-PAYEE');

    $this->actingAs($admin)->get('/reports/expenses?status=pending')->assertSee('PENDING-PAYEE')->assertDontSee('APPROVED-PAYEE');
    $this->actingAs($admin)->get('/reports/expenses?status=rejected')->assertSee('REJECTED-PAYEE')->assertDontSee('APPROVED-PAYEE');

    $all = $this->actingAs($admin)->get('/reports/expenses?status=all')->assertOk();
    $all->assertSee('APPROVED-PAYEE')->assertSee('PENDING-PAYEE')->assertSee('REJECTED-PAYEE');
});

it('totals only what was paid and shows each status separately when they are mixed', function () {
    $account = p3Company();
    p3Expense($account, 'approved', 30_000);
    p3Expense($account, 'pending', 10_000);
    p3Expense($account, 'rejected', 5_000);
    $report = app(ReportRegistry::class)->find('expenses');
    $filters = new ReportFilters(now()->subDay()->toImmutable(), now()->addDay()->toImmutable(), status: 'all');

    $rows = $report->rows($filters, makeAdmin());

    expect($rows)->toHaveCount(3)
        ->and($report->totals($rows)['amount'])->toBe(30_000)
        ->and($report->totalLabel($rows))->toBe('Total approved')
        ->and($report->summary($rows))->toBe([['Pending', 10_000], ['Approved', 30_000], ['Rejected', 5_000]]);

    // One status: a plain total, no summary.
    $pending = $report->rows(new ReportFilters($filters->from, $filters->to, status: 'pending'), makeAdmin());
    expect($report->totals($pending)['amount'])->toBe(10_000)->and($report->totalLabel($pending))->toBe('Total')->and($report->summary($pending))->toBe([]);

    $this->actingAs(makeAdmin())->get('/reports/expenses?status=all')->assertSee('Total approved')->assertSee('$300.00');
});

it('filters the expense report by category, branch and date', function () {
    $account = p3Company();
    $other = Branch::create(['name' => 'Hargeisa', 'city' => 'Hargeisa', 'is_payout_point' => true, 'is_active' => true]);
    $expense = p3Expense($account, 'approved', 10_000, 'ELSEWHERE');
    $expense->update(['branch_id' => $other->id, 'expense_type_id' => ExpenseType::where('name', 'Staff Payroll')->value('id')]);
    p3Expense($account, 'approved', 20_000, 'HERE');
    $admin = makeAdmin();

    $this->actingAs($admin)->get('/reports/expenses?branch_id='.$other->id)->assertSee('ELSEWHERE')->assertDontSee('HERE-');
    $this->actingAs($admin)->get('/reports/expenses?expense_type_id='.ExpenseType::where('name', 'Staff Payroll')->value('id'))
        ->assertSee('ELSEWHERE');
    $this->actingAs($admin)->get('/reports/expenses?from=2000-01-01&to=2000-01-31')->assertDontSee('ELSEWHERE')->assertDontSee('>HERE<', false);
});

it('gives the by-category report the same status filter', function () {
    $account = p3Company();
    p3Expense($account, 'approved', 30_000);
    p3Expense($account, 'pending', 12_300);
    $report = app(ReportRegistry::class)->find('expenses-by-type');
    $from = now()->subDay()->toImmutable();
    $to = now()->addDay()->toImmutable();

    expect($report->title())->toBe('Expenses by category')
        ->and($report->rows(new ReportFilters($from, $to), makeAdmin())->sum('amount'))->toBe(30_000)
        ->and($report->rows(new ReportFilters($from, $to, status: 'pending'), makeAdmin())->sum('amount'))->toBe(12_300)
        ->and($report->rows(new ReportFilters($from, $to, status: 'all'), makeAdmin())->sum('amount'))->toBe(42_300);

    $this->actingAs(makeAdmin())->get('/reports/expenses-by-type/print?status=pending')->assertOk()->assertSee('Pending');
});

it('exports the expense report to CSV with the status column', function () {
    p3Expense(p3Company(), 'approved', 30_000);

    $csv = $this->actingAs(makeAdmin())->get('/reports/expenses/export')->assertOk()->streamedContent();

    expect($csv)->toContain('No.')->toContain('Status')->toContain('EX-0001')->toContain('300.00');
});

// --- Transfer page print icon ---------------------------------------------

it('puts a print icon on the transfer page even with receipts switched off', function () {
    Settings::set('receipts.enabled', false);
    $agent = H::sendingAgent(fundCents: 100_000);
    $transfer = app(TransferService::class)->create($agent, $this->branch, H::transferPayload(), (string) Str::uuid());

    $this->actingAs($agent)->get("/transfers/{$transfer->id}")->assertOk()
        ->assertSee(route('transfers.print', $transfer), false)
        ->assertSee('Print receipt')
        ->assertDontSee('Download receipt');

    $response = $this->actingAs($agent)->get("/transfers/{$transfer->id}/print")->assertOk();
    expect($response->headers->get('content-type'))->toContain('application/pdf')
        ->and(substr($response->getContent(), 0, 4))->toBe('%PDF');
});

it('keeps the transfer print to people who may see the transfer', function () {
    $agent = H::sendingAgent(fundCents: 100_000);
    $transfer = app(TransferService::class)->create($agent, $this->branch, H::transferPayload(), (string) Str::uuid());
    $stranger = H::sendingAgent(fundCents: 0);

    $this->actingAs($stranger)->get("/transfers/{$transfer->id}/print")->assertForbidden();
});

it('prints the transfer receipt with the new logo and HW number', function () {
    $agent = H::sendingAgent(fundCents: 100_000);
    $transfer = app(TransferService::class)->create($agent, $this->branch, H::transferPayload(), (string) Str::uuid());

    $html = view('transfers.receipt', [
        'transfer' => $transfer->load(['branch', 'sendingAgent']),
        'companyName' => 'Blue Star',
        'logo' => Brand::logoDataUri(),
        'timezone' => 'Africa/Mogadishu',
    ])->render();

    expect($transfer->code)->toBe('HW-0001')
        ->and($html)->toContain('HW-0001')->toContain('data:image/png;base64');
});

it('limits the PDF to what the server memory can build, never above the configured cap', function () {
    $limit = new ReflectionMethod(ReportsController::class, 'pdfRowLimit');
    $controller = app(ReportsController::class);

    config(['hawala.reports.pdf_max_rows' => 2000]);
    ini_set('memory_limit', '1G');
    expect($limit->invoke($controller))->toBe(2000);

    ini_set('memory_limit', '256M');
    $rows = $limit->invoke($controller);
    expect($rows)->toBeGreaterThan(300)->toBeLessThan(700);

    ini_set('memory_limit', '-1');
    config(['hawala.reports.pdf_max_rows' => 123]);
    expect($limit->invoke($controller))->toBe(123);

    // The cap wins even over a roomy server.
    ini_set('memory_limit', '1G');
    expect($limit->invoke($controller))->toBe(123);
});
