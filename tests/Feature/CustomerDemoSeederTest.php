<?php

use App\Enums\BalanceRequestKind;
use App\Enums\LedgerAccountType;
use App\Enums\QuoteMode;
use App\Enums\TransferStatus;
use App\Models\Branch;
use App\Models\CompanyAccount;
use App\Models\JournalLine;
use App\Models\User;
use App\Services\Balances\BalanceRequestService;
use App\Services\Ledger\LedgerService;
use App\Services\Transfers\PayoutQueueService;
use App\Services\Transfers\TransferService;
use Database\Seeders\CustomerDemoSeeder;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

beforeEach(function () {
    Notification::fake();
    Http::fake(['*' => Http::response([], 500)]); // no network in tests
    putenv('SUPER_ADMIN_PASSWORD=myadmin101');
});

afterEach(fn () => putenv('SUPER_ADMIN_PASSWORD'));

function demoBalance(User|CompanyAccount $owner, LedgerAccountType $type): int
{
    return app(LedgerService::class)->accountFor($owner, $type)->fresh()->balance_cents;
}

it('sets up the branch, offices and two super admin agents', function () {
    $this->seed(CustomerDemoSeeder::class);

    $omar = User::where('email', 'omar@bluestar.so')->first();
    $yusuf = User::where('email', 'yusuf@bluestar.so')->first();

    expect(Branch::pluck('name')->all())->toBe(['Mogadishu (Banadir)', 'Brussels'])
        ->and(CompanyAccount::pluck('name')->sort()->values()->all())->toBe(['Brussels Office', 'Mogadishu Office'])
        ->and($omar->hasRole('super-admin'))->toBeTrue()
        ->and($yusuf->hasRole('super-admin'))->toBeTrue()
        ->and($yusuf->branch->name)->toBe('Mogadishu (Banadir)')
        ->and($yusuf->payoutAccounts()->count())->toBe(3)
        ->and(demoBalance(CompanyAccount::firstWhere('name', 'Mogadishu Office'), LedgerAccountType::Company))->toBe(2000000);

    foreach (['omar@bluestar.so', 'yusuf@bluestar.so'] as $email) {
        $this->post('/login', ['login' => $email, 'password' => 'myadmin101'])->assertRedirect(route('dashboard'));
        $this->post('/logout');
    }
});

it('supports the full demo flow: top-up, funding, send, pay out', function () {
    $this->seed(CustomerDemoSeeder::class);

    $omar = User::firstWhere('email', 'omar@bluestar.so');
    $yusuf = User::firstWhere('email', 'yusuf@bluestar.so');
    $requests = app(BalanceRequestService::class);

    // Omar pays $1,000 into Brussels; Yusuf approves.
    $topup = $requests->submit(BalanceRequestKind::TopupPaid, $omar, 100000, $omar, CompanyAccount::firstWhere('name', 'Brussels Office'));
    $requests->approve($topup, $yusuf);

    // Yusuf asks for $2,000 from Mogadishu; Omar approves.
    $funding = $requests->submit(BalanceRequestKind::PayoutFunding, $yusuf, 200000, $yusuf, CompanyAccount::firstWhere('name', 'Mogadishu Office'));
    $requests->approve($funding, $omar);

    expect(demoBalance($omar, LedgerAccountType::AgentSend))->toBe(100000)
        ->and(demoBalance($yusuf, LedgerAccountType::AgentPayout))->toBe(200000);

    $transfer = app(TransferService::class)->create($omar, $yusuf->branch, [
        'sender_name' => 'Test Sender', 'sender_phone' => '+32470000001',
        'receiver_name' => 'Test Receiver', 'receiver_phone' => '+252610000001',
        'receiver_account_type_id' => null, 'receiver_account_number' => null,
        'currency' => 'USD', 'mode' => QuoteMode::Receive, 'amount_cents' => 10000,
        'is_uncollected' => false, 'note' => null,
    ], (string) Str::uuid());

    $queue = app(PayoutQueueService::class);
    $queue->claim($transfer, $yusuf);
    $paid = $queue->markPaid($transfer->fresh(), $yusuf, $yusuf->payoutAccounts()->first(), 'REF1');

    expect($paid->status)->toBe(TransferStatus::Paid)
        ->and((int) JournalLine::sum('amount_cents'))->toBe(0);

    $this->artisan('hawala:verify-ledger')->expectsOutputToContain('Ledger OK')->assertSuccessful();
});

it('is safe to run twice', function () {
    $this->seed(CustomerDemoSeeder::class);
    $this->seed(CustomerDemoSeeder::class);

    expect(User::count())->toBe(2)
        ->and(Branch::count())->toBe(2)
        ->and(CompanyAccount::count())->toBe(2)
        ->and(demoBalance(CompanyAccount::firstWhere('name', 'Mogadishu Office'), LedgerAccountType::Company))->toBe(2000000);
});

it('refuses to run without a password in the environment', function () {
    putenv('SUPER_ADMIN_PASSWORD');

    expect(fn () => $this->seed(CustomerDemoSeeder::class))->toThrow(RuntimeException::class);
});
