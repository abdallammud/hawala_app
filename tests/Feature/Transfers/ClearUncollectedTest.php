<?php

use App\Models\LedgerAccount;
use App\Services\Transfers\TransferCollectionService;
use App\Services\Transfers\TransferService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;
use Tests\Feature\Transfers\TransferTestHelpers as H;

beforeEach(function () {
    Notification::fake();
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->branch = H::seedReferenceData();
    $this->agent = H::sendingAgent(fundCents: 100000);
    $this->transfer = app(TransferService::class)->create(
        $this->agent, $this->branch, H::transferPayload(['amount_cents' => 10000, 'is_uncollected' => true]), (string) Str::uuid()
    );
});

it('lets an admin clear the flag with a reason, logs it, and leaves balances alone', function () {
    $admin = makeAdmin();
    $balancesBefore = LedgerAccount::query()->orderBy('id')->pluck('balance_cents', 'id')->all();

    $this->actingAs($admin)
        ->post(route('transfers.clear-uncollected', $this->transfer), ['reason' => 'Ticked by mistake'])
        ->assertRedirect()
        ->assertSessionHas('status');

    expect($this->transfer->fresh()->is_uncollected)->toBeFalse();

    $log = Activity::query()->where('description', 'Uncollected flag cleared')->firstOrFail();
    expect($log->causer_id)->toBe($admin->id)
        ->and($log->subject_id)->toBe($this->transfer->id)
        ->and($log->properties['reason'])->toBe('Ticked by mistake');

    expect(LedgerAccount::query()->orderBy('id')->pluck('balance_cents', 'id')->all())->toBe($balancesBefore);
});

it('requires a reason', function () {
    $this->actingAs(makeAdmin())
        ->post(route('transfers.clear-uncollected', $this->transfer), [])
        ->assertSessionHasErrors('reason');

    expect($this->transfer->fresh()->is_uncollected)->toBeTrue();
});

it('is refused for the sending agent', function () {
    $this->actingAs($this->agent)
        ->post(route('transfers.clear-uncollected', $this->transfer), ['reason' => 'x'])
        ->assertForbidden();

    expect($this->transfer->fresh()->is_uncollected)->toBeTrue();
});

it('is refused once the transfer is fully collected', function () {
    app(TransferCollectionService::class)->record($this->transfer, $this->agent, 10500, 'USD', now());

    $this->actingAs(makeAdmin())
        ->post(route('transfers.clear-uncollected', $this->transfer->fresh()), ['reason' => 'x'])
        ->assertForbidden();
});

it('shows the clear link to admins only', function () {
    $this->actingAs(makeAdmin())->get(route('transfers.show', $this->transfer))
        ->assertOk()->assertSee('Clear uncollected flag');

    $this->actingAs($this->agent)->get(route('transfers.show', $this->transfer))
        ->assertOk()->assertDontSee('Clear uncollected flag');
});
