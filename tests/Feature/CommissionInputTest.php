<?php

use App\Enums\CommissionType;
use App\Enums\UserType;
use App\Models\Branch;
use App\Models\User;
use App\Support\CommissionInput;
use Database\Seeders\ReferenceDataSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->seed(ReferenceDataSeeder::class);
});

function agentPayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'Ali Hassan',
        'email' => 'ali@example.com',
        'password' => 'password123',
        'type' => UserType::SendingAgent->value,
        'branch_id' => Branch::query()->first()->id,
        'role' => 'sending-agent',
    ], $overrides);
}

it('converts typed values to basis points and cents', function () {
    expect(CommissionInput::toStored('percent', '2.5'))->toBe(250)
        ->and(CommissionInput::toStored('percent', '5'))->toBe(500)
        ->and(CommissionInput::toStored('percent', '33.33'))->toBe(3333)
        ->and(CommissionInput::toStored('flat', '0.20'))->toBe(20)
        ->and(CommissionInput::toStored('flat', '0.29'))->toBe(29)
        ->and(CommissionInput::toStored('flat', '10'))->toBe(1000)
        ->and(CommissionInput::toStored(null, '5'))->toBeNull()
        ->and(CommissionInput::toStored('none', '5'))->toBeNull()
        ->and(CommissionInput::toStored('percent', ''))->toBeNull();
});

it('converts stored values back to what people type', function () {
    expect(CommissionInput::toDisplay('percent', 250))->toBe('2.5')
        ->and(CommissionInput::toDisplay('percent', 500))->toBe('5')
        ->and(CommissionInput::toDisplay('percent', 3333))->toBe('33.33')
        ->and(CommissionInput::toDisplay('flat', 20))->toBe('0.20')
        ->and(CommissionInput::toDisplay('flat', 1000))->toBe('10.00')
        ->and(CommissionInput::toDisplay('percent', null))->toBe('');
});

it('stores a percent commission typed as 2.5 as 250 basis points', function () {
    $this->actingAs(makeAdmin())->post('/users', agentPayload([
        'send_commission_type' => 'percent',
        'send_commission_value' => '2.5',
    ]))->assertRedirect('/users');

    $user = User::query()->where('email', 'ali@example.com')->firstOrFail();

    expect($user->send_commission_type)->toBe(CommissionType::Percent)
        ->and($user->send_commission_value)->toBe(250);
});

it('stores a flat commission typed as 0.20 as 20 cents', function () {
    $this->actingAs(makeAdmin())->post('/users', agentPayload([
        'send_commission_type' => 'flat',
        'send_commission_value' => '0.20',
    ]))->assertRedirect('/users');

    expect(User::query()->where('email', 'ali@example.com')->value('send_commission_value'))->toBe(20);
});

it('rejects more than two decimals and percentages over 100', function () {
    $admin = makeAdmin();

    $this->actingAs($admin)->post('/users', agentPayload(['send_commission_type' => 'percent', 'send_commission_value' => '2.555']))
        ->assertSessionHasErrors('send_commission_value');
    $this->actingAs($admin)->post('/users', agentPayload(['send_commission_type' => 'percent', 'send_commission_value' => '101']))
        ->assertSessionHasErrors('send_commission_value');
    $this->actingAs($admin)->post('/users', agentPayload(['send_commission_type' => 'flat', 'send_commission_value' => '-1']))
        ->assertSessionHasErrors('send_commission_value');
});

it('ignores a value when the type is use default', function () {
    $this->actingAs(makeAdmin())->post('/users', agentPayload(['send_commission_value' => '7']))
        ->assertRedirect('/users');

    expect(User::query()->where('email', 'ali@example.com')->value('send_commission_value'))->toBeNull();
});

it('shows stored values as plain numbers on the edit form', function () {
    $agent = User::factory()->create([
        'type' => UserType::SendingAgent,
        'send_commission_type' => CommissionType::Percent,
        'send_commission_value' => 250,
    ]);

    $this->actingAs(makeAdmin())->get("/users/{$agent->id}/edit")
        ->assertOk()
        ->assertSee('value="2.5"', false);
});

it('words commission changes in the audit log as percent and dollars', function () {
    $admin = makeSuperAdmin();
    $this->actingAs($admin);

    $agent = User::factory()->create([
        'type' => UserType::SendingAgent,
        'send_commission_type' => CommissionType::Percent,
        'send_commission_value' => 250,
    ]);
    $agent->update(['send_commission_value' => 500]);

    $entry = Activity::query()->where('subject_id', $agent->id)->where('event', 'updated')->latest('id')->first();
    expect($entry->properties['context']['send_commission_type'])->toBe('percent');

    $this->get(route('audit.index'))->assertOk()->assertSee('2.5%')->assertSee('5%');
});
