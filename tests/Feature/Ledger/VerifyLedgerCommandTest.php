<?php

use App\Enums\JournalType;
use App\Enums\LedgerAccountType;
use App\Models\CompanyAccount;
use App\Models\JournalEntry;
use App\Models\LedgerAccount;
use App\Models\User;
use App\Services\Ledger\LedgerService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;

it('reports OK on an empty ledger', function () {
    $exitCode = Artisan::call('hawala:verify-ledger');

    expect($exitCode)->toBe(0)
        ->and(Artisan::output())->toContain('Ledger OK');
});

it('reports OK after a healthy sequence of postings', function () {
    $service = app(LedgerService::class);
    $admin = User::factory()->create();
    $agent = User::factory()->create();
    $company = CompanyAccount::create(['name' => 'Europe office', 'location' => 'europe', 'is_active' => true]);

    $companyLedger = $service->accountFor($company, LedgerAccountType::Company);
    $send = $service->accountFor($agent, LedgerAccountType::AgentSend);

    $service->post(JournalType::TopupPaid, [[$companyLedger, 1000_00], [$send, -1000_00]], null, $admin);

    $exitCode = Artisan::call('hawala:verify-ledger');

    expect($exitCode)->toBe(0)
        ->and(Artisan::output())->toContain('Ledger OK');
});

it('detects a cached balance that no longer matches its journal lines', function () {
    $service = app(LedgerService::class);
    $admin = User::factory()->create();
    $agent = User::factory()->create();
    $company = CompanyAccount::create(['name' => 'Europe office', 'location' => 'europe', 'is_active' => true]);

    $companyLedger = $service->accountFor($company, LedgerAccountType::Company);
    $send = $service->accountFor($agent, LedgerAccountType::AgentSend);

    $service->post(JournalType::TopupPaid, [[$companyLedger, 1000_00], [$send, -1000_00]], null, $admin);

    // Corrupt the cache directly, bypassing LedgerService — simulates drift.
    LedgerAccount::query()->where('id', $send->id)->update(['balance_cents' => 999999]);

    $exitCode = Artisan::call('hawala:verify-ledger');

    expect($exitCode)->toBe(1)
        ->and(Artisan::output())->toContain('cached balance');
});

it('detects an unbalanced journal entry inserted outside the service', function () {
    $service = app(LedgerService::class);
    $admin = User::factory()->create();
    $agent = User::factory()->create();
    $company = CompanyAccount::create(['name' => 'Europe office', 'location' => 'europe', 'is_active' => true]);

    $companyLedger = $service->accountFor($company, LedgerAccountType::Company);
    $send = $service->accountFor($agent, LedgerAccountType::AgentSend);

    $entry = JournalEntry::create([
        'uuid' => (string) Str::uuid(),
        'type' => JournalType::ManualAdjustment,
        'created_by' => $admin->id,
        'created_at' => now(),
    ]);
    $entry->lines()->create(['ledger_account_id' => $companyLedger->id, 'amount_cents' => 100, 'created_at' => now()]);
    $entry->lines()->create(['ledger_account_id' => $send->id, 'amount_cents' => -50, 'created_at' => now()]);

    $exitCode = Artisan::call('hawala:verify-ledger');

    expect($exitCode)->toBe(1)
        ->and(Artisan::output())->toContain('does not balance');
});

it('detects a guarded balance that went negative outside the service', function () {
    $service = app(LedgerService::class);
    $agent = User::factory()->create();

    $send = $service->accountFor($agent, LedgerAccountType::AgentSend);
    LedgerAccount::query()->where('id', $send->id)->update(['balance_cents' => -500]);

    $exitCode = Artisan::call('hawala:verify-ledger');

    expect($exitCode)->toBe(1)
        ->and(Artisan::output())->toContain('is negative');
});
