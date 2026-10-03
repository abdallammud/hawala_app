<?php

use App\Enums\SmsEvent;
use App\Enums\SmsStatus;
use App\Models\SmsLog;
use App\Models\SmsTemplate;
use App\Services\Sms\HormuudDriver;
use App\Services\Sms\SmsSender;
use App\Services\Sms\SmsTemplateRenderer;
use App\Services\Sms\TabaarakDriver;
use App\Services\Transfers\PayoutQueueService;
use App\Services\Transfers\TransferCancellationService;
use App\Services\Transfers\TransferService;
use App\Support\Settings;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\SmsTemplatesSeeder;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\Feature\Transfers\TransferTestHelpers as H;

beforeEach(function () {
    Notification::fake();
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->seed(SmsTemplatesSeeder::class);
    $this->branch = H::seedReferenceData();
    fakeTabaarak();
});

function activate(SmsEvent $event): SmsTemplate
{
    return tap(SmsTemplate::where('event', $event)->firstOrFail())->update(['is_active' => true]);
}

it('seeds exactly one inactive template per event', function () {
    expect(SmsTemplate::count())->toBe(count(SmsEvent::cases()))
        ->and(SmsTemplate::where('is_active', true)->count())->toBe(0);

    foreach (SmsEvent::cases() as $event) {
        expect(SmsTemplate::where('event', $event)->exists())->toBeTrue();
    }
});

it('fills every placeholder and leaves no raw braces behind', function () {
    $renderer = app(SmsTemplateRenderer::class);

    $body = implode(' ', SmsTemplateRenderer::PLACEHOLDERS);

    $rendered = $renderer->render($body, $renderer->sampleValues());

    expect($rendered)->not->toContain('{')
        ->and($rendered)->not->toContain('}')
        ->and($rendered)->toContain('HW-AB12C');
});

it('renders an unknown placeholder as empty rather than leaking it to a customer', function () {
    $rendered = app(SmsTemplateRenderer::class)->render('Hello {receiver_name}, ref {code}.', []);

    expect($rendered)->toBe('Hello , ref .');
});

it('sends nothing at all while sms.enabled is off', function () {
    Settings::set('sms.enabled', false);
    activate(SmsEvent::TransferSent);

    $agent = H::sendingAgent(fundCents: 500000);

    app(TransferService::class)->create(
        $agent, $this->branch, H::transferPayload(), (string) Str::uuid()
    );

    expect(SmsLog::count())->toBe(0);
});

it('sends nothing when the template is inactive', function () {
    Settings::set('sms.enabled', true);
    // Template left inactive on purpose.

    $agent = H::sendingAgent(fundCents: 500000);

    app(TransferService::class)->create(
        $agent, $this->branch, H::transferPayload(), (string) Str::uuid()
    );

    expect(SmsLog::count())->toBe(0);
});

it('logs one message per recipient when a transfer is sent', function () {
    Settings::set('sms.enabled', true);

    // Seeded template targets sender + receiver.
    activate(SmsEvent::TransferSent);

    $agent = H::sendingAgent(fundCents: 500000);

    $transfer = app(TransferService::class)->create(
        $agent, $this->branch, H::transferPayload(['amount_cents' => 25000]), (string) Str::uuid()
    );

    $logs = SmsLog::all();

    expect($logs)->toHaveCount(2)
        ->and($logs->pluck('to')->all())->toEqualCanonicalizing([$transfer->sender_phone, $transfer->receiver_phone])
        // The Somali receiver gets it; the gateway cannot reach the +46 sender.
        ->and($logs->firstWhere('to', $transfer->receiver_phone)->status)->toBe(SmsStatus::Sent)
        ->and($logs->firstWhere('to', $transfer->sender_phone)->status)->toBe(SmsStatus::Failed)
        ->and($logs->firstWhere('to', $transfer->sender_phone)->provider_response)->toContain('not a valid Somali mobile number')
        ->and($logs->first()->transfer_id)->toBe($transfer->id)
        ->and($logs->first()->event)->toBe(SmsEvent::TransferSent)
        ->and($logs->first()->body)->toContain($transfer->code)
        ->and($logs->first()->body)->toContain('$250.00');
});

it('does not tell the receiver a held large transfer is on its way', function () {
    Settings::set('sms.enabled', true);
    Settings::set('fees.hold_large_transfers', true);
    activate(SmsEvent::TransferSent);

    $agent = H::sendingAgent(fundCents: 5000000);

    $transfer = app(TransferService::class)->create(
        $agent, $this->branch, H::transferPayload(['amount_cents' => 400000]), (string) Str::uuid()
    );

    expect($transfer->status->value)->toBe('awaiting_approval')
        ->and(SmsLog::where('event', SmsEvent::TransferSent)->count())->toBe(0);

    // It fires once an admin releases it.
    app(TransferCancellationService::class)->approveLarge($transfer, makeAdmin());

    expect(SmsLog::where('event', SmsEvent::TransferSent)->count())->toBe(2);
});

it('messages the payout agent when a claim times out', function () {
    Settings::set('sms.enabled', true);
    activate(SmsEvent::ClaimExpired);

    $sender = H::sendingAgent(fundCents: 500000);
    $payer = H::payoutAgent($this->branch, fundCents: 500000);

    $transfer = app(TransferService::class)->create(
        $sender, $this->branch, H::transferPayload(), (string) Str::uuid()
    );

    app(PayoutQueueService::class)->claim($transfer, $payer);
    $transfer->fresh()->update(['claim_expires_at' => now()->subMinute()]);
    app(PayoutQueueService::class)->expireDueClaims();

    $log = SmsLog::where('event', SmsEvent::ClaimExpired)->first();

    // The transfer row has already lost claimed_by by this point.
    expect($log)->not->toBeNull()
        ->and($log->to)->toBe($payer->phone)
        ->and($log->body)->toContain($transfer->code);
});

it('records a failed send instead of breaking the money operation', function () {
    Settings::set('sms.enabled', true);

    useGateway('tabaarak', ['api_url' => 'https://down.test']);
    Http::fake(['down.test/*' => Http::response('rejected', 500)]);

    activate(SmsEvent::TransferSent);

    $agent = H::sendingAgent(fundCents: 500000);

    // The transfer must still succeed.
    $transfer = app(TransferService::class)->create(
        $agent, $this->branch, H::transferPayload(), (string) Str::uuid()
    );

    expect($transfer->exists)->toBeTrue()
        ->and(SmsLog::where('status', SmsStatus::Failed)->count())->toBe(2);
});

it('fails clearly when no provider has been selected', function () {
    Settings::set('sms.driver', null);

    $log = app(SmsSender::class)->send('+252612345678', 'hi', SmsEvent::TransferSent);

    expect($log->status)->toBe(SmsStatus::Failed)
        ->and($log->provider)->toBeNull();

    Settings::set('sms.enabled', true);

    expect(app(SmsSender::class)->send('+252612345678', 'hi', SmsEvent::TransferSent)->provider_response)
        ->toContain('No SMS provider is selected');
});

it('picks the driver from settings', function () {
    Settings::set('sms.driver', 'tabaarak');
    expect(app(SmsSender::class)->driver())->toBeInstanceOf(TabaarakDriver::class);

    Settings::set('sms.driver', 'hormuud');
    expect(app(SmsSender::class)->driver())->toBeInstanceOf(HormuudDriver::class);
});

it('sends a test message even while sms.enabled is off, without logging it', function () {
    Settings::set('sms.enabled', false);

    $response = app(SmsSender::class)->sendTest('+252612345678', 'test');

    expect($response)->toContain('Sent')
        ->and(SmsLog::count())->toBe(0);
});

it('never sends to an agent who has no phone number on file', function () {
    Settings::set('sms.enabled', true);

    $template = activate(SmsEvent::TransferSent);
    $template->update(['to_sender' => false, 'to_receiver' => false, 'to_sending_agent' => true]);

    $agent = H::sendingAgent(fundCents: 500000);
    $agent->update(['phone' => null]);

    app(TransferService::class)->create(
        $agent, $this->branch, H::transferPayload(), (string) Str::uuid()
    );

    expect(SmsLog::count())->toBe(0);
});

it('deduplicates when one person is two recipients at once', function () {
    Settings::set('sms.enabled', true);

    $agent = H::sendingAgent(fundCents: 500000);

    $template = activate(SmsEvent::TransferSent);
    $template->update(['to_sender' => true, 'to_receiver' => true, 'to_sending_agent' => true]);

    // The agent's own number is also the sender's number.
    app(TransferService::class)->create(
        $agent, $this->branch, H::transferPayload(['sender_phone' => $agent->phone, 'receiver_phone' => $agent->phone]),
        (string) Str::uuid()
    );

    expect(SmsLog::count())->toBe(1);
});
