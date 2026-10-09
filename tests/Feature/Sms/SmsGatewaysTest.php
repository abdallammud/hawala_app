<?php

use App\Enums\SmsEvent;
use App\Enums\SmsProvider;
use App\Enums\SmsStatus;
use App\Services\Sms\GatewayCredentials;
use App\Services\Sms\SmsSender;
use App\Services\Sms\TokenGatewayDriver;
use App\Support\Settings;
use App\Support\SomaliPhone;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    TokenGatewayDriver::flushTokens();
    Settings::set('sms.enabled', true);
});

/** The minimum valid settings form, with overrides. */
function settingsForm(array $overrides = []): array
{
    return array_merge([
        'fees_mode' => 'whole',
        'fees_min_fee' => '0',
        'fees_large_transfer' => '3000',
        'commission_send_type' => 'percent',
        'commission_send_value' => '5000',
        'commission_payout_type' => 'percent',
        'commission_payout_value' => '2000',
        'currency_margin_bp' => '0',
        'currency_max_round_up_bp' => '500',
        'currency_max_rate_age_hours' => '72',
        'payout_claim_minutes' => '30',
        'general_company_name' => 'Blue Star',
        'security_idle_timeout_minutes' => 15,
        'general_timezone' => 'Africa/Mogadishu',
        'general_duplicate_warning_minutes' => '10',
    ], $overrides);
}

// --- Phone normalization -------------------------------------------------------

it('folds every Somali format into 252 plus nine digits', function (string $input) {
    expect(SomaliPhone::normalize($input))->toBe('252616957070');
})->with([
    '+252616957070', '252616957070', '2520616957070', '0616957070',
    '616957070', '6957070', '957070', '+252 61 695 7070',
]);

it('rejects numbers it cannot rebuild with confidence', function (?string $input) {
    expect(SomaliPhone::normalize($input))->toBeNull();
})->with(['12345', '+46701234567', '', null]);

// --- Tabaarak ---------------------------------------------------------------

it('sends through Tabaarak with the normalized number and logs in once per batch', function () {
    useGateway('tabaarak');

    Http::fake([
        'sms.tabaarak.com/Auth/SMSLogin' => Http::response(['data' => ['token' => 'tok-1']]),
        'sms.tabaarak.com/Sms/sendsms' => Http::response(['success' => true, 'message' => 'Sent']),
    ]);

    $sender = app(SmsSender::class);
    $first = $sender->send('+252616957070', 'Hello', SmsEvent::TransferSent);
    $second = $sender->send('0615111222', 'Hello', SmsEvent::TransferSent);

    expect($first->status)->toBe(SmsStatus::Sent)
        ->and($second->status)->toBe(SmsStatus::Sent)
        ->and($first->provider)->toBe('tabaarak');

    Http::assertSentCount(3); // one login, two sends
    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/Auth/SMSLogin')
        && $r['Name'] === 'bluestar' && $r['Password'] === 's3cret');
    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/Sms/sendsms')
        && $r->hasHeader('Authorization', 'Bearer tok-1')
        && $r['mobile'] === ['252616957070'] && $r['smsMessage'] === 'Hello');
});

it('records a Tabaarak 200 without success:true as failed', function () {
    useGateway('tabaarak');

    Http::fake([
        '*/Auth/SMSLogin' => Http::response(['data' => ['token' => 'tok']]),
        '*/Sms/sendsms' => Http::response(['success' => false, 'message' => 'Insufficient balance']),
    ]);

    $log = app(SmsSender::class)->send('616957070', 'Hello', SmsEvent::TransferSent);

    expect($log->status)->toBe(SmsStatus::Failed)
        ->and($log->provider_response)->toBe('Insufficient balance');
});

it('reports a bad Tabaarak login as a failed send', function () {
    useGateway('tabaarak');

    Http::fake(['*/Auth/SMSLogin' => Http::response(['message' => 'Invalid credentials'], 401)]);

    $log = app(SmsSender::class)->send('616957070', 'Hello', SmsEvent::TransferSent);

    expect($log->status)->toBe(SmsStatus::Failed)
        ->and($log->provider_response)->toContain('Invalid credentials');
});

it('uses a custom API URL when one is saved', function () {
    useGateway('tabaarak', ['api_url' => 'https://alt.tabaarak.test/']);

    Http::fake([
        'alt.tabaarak.test/Auth/SMSLogin' => Http::response(['data' => ['token' => 'tok']]),
        'alt.tabaarak.test/Sms/sendsms' => Http::response(['success' => true]),
    ]);

    expect(app(SmsSender::class)->send('616957070', 'Hi', SmsEvent::TransferSent)->status)->toBe(SmsStatus::Sent);
});

// --- Hormuud ----------------------------------------------------------------

it('sends through Hormuud with the sender ID and ResponseCode 200', function () {
    useGateway('hormuud');

    Http::fake([
        'smsapi.hormuud.com/token' => Http::response(['access_token' => 'h-tok']),
        'smsapi.hormuud.com/api/SendSMS' => Http::response(['ResponseCode' => '200', 'ResponseMessage' => 'SUCCESS!.']),
    ]);

    $log = app(SmsSender::class)->send('0616957070', 'Hello', SmsEvent::TransferSent);

    expect($log->status)->toBe(SmsStatus::Sent)
        ->and($log->provider)->toBe('hormuud');

    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/token')
        && $r['Username'] === 'bluestar' && $r['grant_type'] === 'password');
    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/api/SendSMS')
        && $r->hasHeader('Authorization', 'Bearer h-tok')
        && $r->header('Content-Type')[0] === 'application/json; charset=utf-8'
        && $r['mobile'] === '252616957070' && $r['senderid'] === 'BLUESTAR');
});

it('treats a Hormuud HTTP 200 with a failing ResponseCode as failed and keeps the real reason', function () {
    useGateway('hormuud');

    Http::fake([
        '*/token' => Http::response(['access_token' => 'h-tok']),
        // Lower-case keys on purpose: casing varies between accounts.
        '*/api/SendSMS' => Http::response(['responsecode' => 204, 'responsemessage' => 'Failed.', 'data' => ['description' => 'Zero Balance!!']]),
    ]);

    $log = app(SmsSender::class)->send('616957070', 'Hello', SmsEvent::TransferSent);

    expect($log->status)->toBe(SmsStatus::Failed)
        ->and($log->provider_response)->toBe('Zero Balance!! (code 204)');
});

it('falls back to the Hormuud code table when there is no description', function () {
    useGateway('hormuud');

    Http::fake([
        '*/token' => Http::response(['access_token' => 'h-tok']),
        '*/api/SendSMS' => Http::response(['ResponseCode' => '203', 'ResponseMessage' => 'Failed.']),
    ]);

    expect(app(SmsSender::class)->send('616957070', 'Hi', SmsEvent::TransferSent)->provider_response)
        ->toBe('Invalid sender ID (code 203)');
});

it('logs in again once when Hormuud rejects a cached token', function () {
    useGateway('hormuud');

    Http::fake([
        '*/token' => Http::sequence()
            ->push(['access_token' => 'old'])
            ->push(['access_token' => 'new']),
        '*/api/SendSMS' => Http::sequence()
            ->push(['ResponseCode' => '200'])
            ->push(['ResponseCode' => '201', 'ResponseMessage' => 'Failed.'])
            ->push(['ResponseCode' => '200']),
    ]);

    $sender = app(SmsSender::class);
    $sender->send('616957070', 'One', SmsEvent::TransferSent);
    $log = $sender->send('616957070', 'Two', SmsEvent::TransferSent);

    expect($log->status)->toBe(SmsStatus::Sent);
    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/api/SendSMS') && $r->hasHeader('Authorization', 'Bearer new'));
});

// --- Shared rules -------------------------------------------------------------

it('rejects a number it cannot normalize without calling the gateway', function () {
    useGateway('hormuud');
    Http::fake();

    $log = app(SmsSender::class)->send('+46701234567', 'Hello', SmsEvent::TransferSent);

    expect($log->status)->toBe(SmsStatus::Failed)
        ->and($log->provider_response)->toContain('not a valid Somali mobile number');
    Http::assertNothingSent();
});

it('fails clearly when the selected gateway has no credentials', function () {
    Settings::set('sms.driver', 'tabaarak');
    Http::fake();

    $log = app(SmsSender::class)->send('616957070', 'Hello', SmsEvent::TransferSent);

    expect($log->provider_response)->toContain('not set up yet');
    Http::assertNothingSent();
});

// --- Settings screen ----------------------------------------------------------

it('saves both providers, encrypts the password and keeps it when the box is left blank', function () {
    $admin = makeSuperAdmin();

    $this->actingAs($admin)->put('/setup/settings', settingsForm([
        'sms_driver' => 'hormuud',
        'sms_tabaarak_username' => 'tab-user',
        'sms_tabaarak_password' => 'tab-pass',
        'sms_hormuud_username' => 'hor-user',
        'sms_hormuud_password' => 'hor-pass',
        'sms_hormuud_sender_id' => 'BLUESTAR',
    ]))->assertRedirect()->assertSessionHasNoErrors();

    $stored = Settings::get('sms.hormuud');

    expect(Settings::get('sms.driver'))->toBe('hormuud')
        ->and($stored['password'])->not->toBe('hor-pass')
        ->and(GatewayCredentials::forProvider(SmsProvider::Hormuud)->password)->toBe('hor-pass')
        ->and(GatewayCredentials::forProvider(SmsProvider::Tabaarak)->username)->toBe('tab-user');

    // Switch to Tabaarak with every password box blank: both stay saved.
    $this->actingAs($admin)->put('/setup/settings', settingsForm([
        'sms_driver' => 'tabaarak',
        'sms_tabaarak_username' => 'tab-user',
        'sms_hormuud_username' => 'hor-user',
        'sms_hormuud_sender_id' => 'BLUESTAR',
    ]))->assertRedirect()->assertSessionHasNoErrors();

    expect(GatewayCredentials::forProvider(SmsProvider::Hormuud)->password)->toBe('hor-pass')
        ->and(GatewayCredentials::forProvider(SmsProvider::Tabaarak)->password)->toBe('tab-pass');
});

it('never renders a saved password into the settings page', function () {
    useGateway('tabaarak');

    $this->actingAs(makeSuperAdmin())->get('/setup/settings')
        ->assertOk()
        ->assertDontSee('s3cret')
        ->assertDontSee(Settings::get('sms.tabaarak.password'))
        ->assertSee(__('Saved. Leave blank to keep it.'));
});

it('refuses to switch SMS on with a half-configured gateway', function () {
    $admin = makeSuperAdmin();

    $this->actingAs($admin)->put('/setup/settings', settingsForm([
        'sms_enabled' => '1',
        'sms_driver' => 'tabaarak',
    ]))->assertSessionHasErrors('sms_tabaarak_username');

    $this->actingAs($admin)->put('/setup/settings', settingsForm([
        'sms_enabled' => '1',
        'sms_driver' => 'hormuud',
        'sms_hormuud_username' => 'u',
        'sms_hormuud_password' => 'p',
    ]))->assertSessionHasErrors('sms_hormuud_sender_id');
});

it('validates the Hormuud sender length and http(s)-only API URLs', function () {
    $this->actingAs(makeSuperAdmin())->put('/setup/settings', settingsForm([
        'sms_hormuud_sender_id' => 'TWELVE_CHARS',
        'sms_tabaarak_api_url' => 'file:///etc/passwd',
    ]))->assertSessionHasErrors(['sms_hormuud_sender_id', 'sms_tabaarak_api_url']);
});

// --- Test connection ----------------------------------------------------------

it('tests the connection with the form values and sends no SMS', function () {
    Http::fake([
        '*/token' => Http::response(['access_token' => 'h-tok']),
        '*' => Http::response('should not be called', 500),
    ]);

    $this->actingAs(makeSuperAdmin())
        ->postJson(route('setup.settings.sms-test'), ['provider' => 'hormuud', 'username' => 'u', 'password' => 'p'])
        ->assertOk()
        ->assertJson(['ok' => true]);

    Http::assertSentCount(1);
    Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'SendSMS'));
});

it('falls back to the saved password and reports a bad login', function () {
    useGateway('hormuud');

    Http::fake(['*/token' => Http::response(['error' => 'invalid_grant', 'error_description' => 'The user name or password is incorrect.'], 400)]);

    $this->actingAs(makeSuperAdmin())
        ->postJson(route('setup.settings.sms-test'), ['provider' => 'hormuud', 'username' => '', 'password' => ''])
        ->assertOk()
        ->assertJson(['ok' => false, 'message' => 'Failed to get access token: The user name or password is incorrect.']);

    Http::assertSent(fn (Request $r) => $r['Password'] === 's3cret');
});

it('blocks the connection test without setup.settings', function () {
    $this->actingAs(makeAdmin())
        ->postJson(route('setup.settings.sms-test'), ['provider' => 'tabaarak'])
        ->assertForbidden();
});

it('picks up a config default added after the settings cache was warmed', function () {
    Settings::all(); // warm the cache
    config(['hawala.sms.brand_new' => ['username' => '']]);

    expect(Settings::get('sms.brand_new.username'))->toBe('');

    $this->actingAs(makeSuperAdmin())->get('/setup/settings')->assertOk();
});

it('offers only the two gateways and blocks switching SMS on with none selected', function () {
    $admin = makeSuperAdmin();

    $this->actingAs($admin)->get('/setup/settings')
        ->assertOk()
        ->assertSee(__('Select provider'))
        ->assertSee(__('Tabaarak SMS Gateway'))
        ->assertSee(__('Hormuud SMS API'))
        ->assertDontSee('Log only')
        ->assertDontSee('Generic HTTP');

    $this->actingAs($admin)->put('/setup/settings', settingsForm(['sms_enabled' => '1']))
        ->assertSessionHasErrors('sms_driver');
});
