<?php

use App\Enums\SmsEvent;
use App\Models\SmsLog;
use App\Models\SmsTemplate;
use App\Models\User;
use App\Support\Settings;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\SmsTemplatesSeeder;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    Notification::fake();
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->seed(SmsTemplatesSeeder::class);

    $this->template = SmsTemplate::where('event', SmsEvent::TransferSent)->firstOrFail();
});

it('lists every event for a super-admin', function () {
    $response = $this->actingAs(makeSuperAdmin())->get(route('setup.sms-templates.index'));

    $response->assertOk();

    foreach (SmsEvent::cases() as $event) {
        $response->assertSee($event->label());
    }
});

it('blocks an agent from the templates screen', function () {
    $agent = User::factory()->create();
    $agent->assignRole('sending-agent');

    expect($agent->can('setup.sms'))->toBeFalse();

    $this->actingAs($agent)->get(route('setup.sms-templates.index'))->assertForbidden();
    $this->actingAs($agent)->get(route('setup.sms-templates.edit', $this->template))->assertForbidden();
});

it('shows a preview built from sample data', function () {
    $this->actingAs(makeSuperAdmin())
        ->get(route('setup.sms-templates.edit', $this->template))
        ->assertOk()
        // The sample reference, proving placeholders were substituted.
        ->assertSee('HW-AB12C')
        ->assertSee('{code}');
});

it('saves the body and recipients', function () {
    $this->actingAs(makeSuperAdmin())
        ->put(route('setup.sms-templates.update', $this->template), [
            'body' => 'Your transfer {code} of {amount_usd} is on the way.',
            'to_receiver' => '1',
            'is_active' => '1',
        ])
        ->assertRedirect(route('setup.sms-templates.index'));

    $this->template->refresh();

    expect($this->template->body)->toContain('{code}')
        ->and($this->template->is_active)->toBeTrue()
        ->and($this->template->to_receiver)->toBeTrue()
        // Unchecked boxes must turn the flag off, not leave it as it was.
        ->and($this->template->to_sender)->toBeFalse();
});

it('refuses to activate a template with no recipients', function () {
    $this->actingAs(makeSuperAdmin())
        ->put(route('setup.sms-templates.update', $this->template), [
            'body' => 'Nobody will get this.',
            'is_active' => '1',
        ])
        ->assertSessionHasErrors('to_sender');

    expect($this->template->fresh()->is_active)->toBeFalse();
});

it('requires a body', function () {
    $this->actingAs(makeSuperAdmin())
        ->put(route('setup.sms-templates.update', $this->template), ['body' => ''])
        ->assertSessionHasErrors('body');
});

it('sends a test message through the log driver without logging or enabling SMS', function () {
    Settings::set('sms.enabled', false);
    Settings::set('sms.driver', 'log');

    $this->actingAs(makeSuperAdmin())
        ->post(route('setup.sms-templates.test', $this->template), ['test_phone' => '+252612345678'])
        ->assertRedirect()
        ->assertSessionHas('status');

    // A test is a dry run: it must not pollute the real SMS log.
    expect(SmsLog::count())->toBe(0);
});

it('reports a gateway failure back on the form instead of throwing', function () {
    Settings::set('sms.driver', 'http');
    Settings::set('sms.http', ['url' => 'https://gw.test/send', 'params' => ['to' => '{to}']]);

    Http::fake(['gw.test/*' => Http::response('no credit', 402)]);

    $this->actingAs(makeSuperAdmin())
        ->post(route('setup.sms-templates.test', $this->template), ['test_phone' => '+252612345678'])
        ->assertRedirect()
        ->assertSessionHasErrors('test_phone');
});

it('requires a phone number to send a test', function () {
    $this->actingAs(makeSuperAdmin())
        ->post(route('setup.sms-templates.test', $this->template), [])
        ->assertSessionHasErrors('test_phone');
});
