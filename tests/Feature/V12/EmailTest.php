<?php

use App\Enums\BalanceRequestKind;
use App\Enums\EmailEvent;
use App\Enums\JournalType;
use App\Enums\LedgerAccountType;
use App\Enums\UserType;
use App\Jobs\SendEmailLog;
use App\Mail\TemplatedMail;
use App\Models\Branch;
use App\Models\CompanyAccount;
use App\Models\EmailLog;
use App\Models\EmailTemplate;
use App\Models\ExpenseType;
use App\Models\Transfer;
use App\Models\User;
use App\Services\Balances\BalanceRequestService;
use App\Services\Email\EmailSettings;
use App\Services\Email\EmailTemplateRenderer;
use App\Services\Expenses\ExpenseService;
use App\Services\Ledger\LedgerService;
use App\Services\Transfers\PayoutQueueService;
use App\Services\Transfers\TransferCancellationService;
use App\Services\Transfers\TransferService;
use App\Support\Settings;
use Database\Seeders\EmailTemplatesSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\Feature\Transfers\TransferTestHelpers as H;

beforeEach(function () {
    Notification::fake();
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->branch = H::seedReferenceData();
});

/** Switch email on with a saved account. */
function emailOn(): void
{
    Settings::set('email.enabled', true);
    Settings::set('email.username', 'company@gmail.com');
    Settings::set('email.password', EmailSettings::encrypt('abcdefghijklmnop'));
}

function e2Agent(Branch $branch, array $attrs = []): User
{
    $agent = H::payoutAgent($branch);
    $agent->update($attrs + ['email' => Str::slug($agent->name).'-'.$agent->id.'@example.com']);

    return $agent->fresh();
}

function e2Transfer(?User $agent = null, int $cents = 10_000): Transfer
{
    $agent ??= H::sendingAgent(fundCents: 1_000_000);

    return app(TransferService::class)->create($agent, test()->branch, H::transferPayload(['amount_cents' => $cents]), (string) Str::uuid());
}

// --- Off means off ---------------------------------------------------------

it('sends and logs nothing while email is switched off', function () {
    Mail::fake();
    e2Agent($this->branch);

    e2Transfer();

    expect(EmailLog::count())->toBe(0);
    Mail::assertNothingSent();
});

it('sends nothing when it is on but no account is saved', function () {
    Mail::fake();
    Settings::set('email.enabled', true);
    e2Agent($this->branch);

    e2Transfer();

    expect(EmailLog::count())->toBe(0);
});

// --- New transfer → payout agents -----------------------------------------

it('emails every active payout agent of the destination branch when a transfer is recorded', function () {
    Mail::fake();
    emailOn();
    $other = Branch::create(['name' => 'Hargeisa', 'city' => 'Hargeisa', 'is_payout_point' => true, 'is_active' => true]);

    $a = e2Agent($this->branch);
    $b = e2Agent($this->branch);
    e2Agent($other);                                              // other branch
    e2Agent($this->branch, ['is_active' => false]);                // deactivated
    e2Agent($this->branch, ['email_notifications' => false]);      // opted out
    $sender = H::sendingAgent(fundCents: 1_000_000);                // not a payout agent

    $transfer = e2Transfer($sender, 25_000);

    $logs = EmailLog::query()->where('event', EmailEvent::TransferSent)->get();

    expect($logs->pluck('to_email')->sort()->values()->all())->toBe(collect([$a->email, $b->email])->sort()->values()->all())
        ->and($logs->every(fn ($l) => $l->status === EmailLog::SENT))->toBeTrue();

    $mail = $logs->firstWhere('to_email', $a->email);
    expect($mail->subject)->toBe("New transfer {$transfer->code} for Mogadishu — \$250.00")
        ->and($mail->body)->toContain("Hello {$a->name}")->toContain($transfer->code)->toContain('$250.00')->toContain('Farah Omar')
        ->and($mail->body)->toContain(route('queue.index'))
        // Privacy by default: no phone numbers in the email.
        ->and($mail->body)->not->toContain('+252611234567')->not->toContain('+46701234567');

    Mail::assertSent(TemplatedMail::class, 2);
    Mail::assertSent(TemplatedMail::class, fn ($m) => $m->hasTo($a->email));
});

it('does not email the sender who is also a payout agent unless self-claim is allowed', function () {
    Mail::fake();
    emailOn();
    $dual = H::sendingAgent(fundCents: 1_000_000);
    $dual->update(['type' => UserType::DualAgent, 'branch_id' => $this->branch->id]);
    $dual->givePermissionTo('queue.claim');

    e2Transfer($dual);
    expect(EmailLog::count())->toBe(0);

    Settings::set('payout.allow_self_claim', true);
    e2Transfer($dual);
    expect(EmailLog::where('to_email', $dual->email)->count())->toBe(1);
});

it('does not email a held large transfer to the payout agents yet', function () {
    Mail::fake();
    emailOn();
    Settings::set('fees.hold_large_transfers', true);
    Settings::set('fees.large_transfer_cents', 5_000);
    e2Agent($this->branch);
    $admin = makeAdmin();

    e2Transfer(null, 10_000);

    expect(EmailLog::where('event', EmailEvent::TransferSent)->count())->toBe(0)
        // …but the people who approve large transfers are told.
        ->and(EmailLog::where('event', EmailEvent::LargeTransfer)->where('to_email', $admin->email)->count())->toBe(1);
});

// --- Paid → sending agent, with the receipt -------------------------------

it('emails the sending agent the receipt when the transfer is paid', function () {
    Mail::fake();
    emailOn();
    $sender = H::sendingAgent(fundCents: 1_000_000);
    $sender->update(['email' => 'sender@example.com']);
    $payer = e2Agent($this->branch);
    $queue = app(PayoutQueueService::class);

    $transfer = e2Transfer($sender, 20_000);
    $queue->markPaid($queue->claim($transfer, $payer), $payer, H::payoutAccountFor($payer));

    $log = EmailLog::query()->where('event', EmailEvent::TransferPaid)->sole();

    expect($log->to_email)->toBe('sender@example.com')
        ->and($log->status)->toBe(EmailLog::SENT)
        ->and($log->attach_receipt)->toBeTrue()
        ->and($log->subject)->toBe("Transfer {$transfer->code} has been paid")
        ->and($log->body)->toContain($payer->name)->toContain('$200.00');

    Mail::assertSent(TemplatedMail::class, function (TemplatedMail $mail) use ($transfer) {
        $attachments = $mail->attachments();

        return $mail->hasTo('sender@example.com')
            && count($attachments) === 1
            && str_contains($mail->attachments()[0]->as, "receipt-{$transfer->code}.pdf");
    });
});

it('builds a real PDF for the attachment', function () {
    emailOn();
    $transfer = e2Transfer();
    $log = EmailLog::create([
        'event' => EmailEvent::TransferPaid, 'to_email' => 'x@example.com', 'subject' => 's', 'body' => 'b',
        'attach_receipt' => true, 'transfer_id' => $transfer->id,
    ]);

    $mail = new TemplatedMail($log);
    $attachment = $mail->attachments()[0];

    expect($attachment->as)->toBe("receipt-{$transfer->code}.pdf")->and($attachment->mime)->toBe('application/pdf');
});

it('sends no email to a sending agent who opted out', function () {
    Mail::fake();
    emailOn();
    $sender = H::sendingAgent(fundCents: 1_000_000);
    $sender->update(['email_notifications' => false]);
    $payer = e2Agent($this->branch);
    $queue = app(PayoutQueueService::class);

    $transfer = e2Transfer($sender);
    $queue->markPaid($queue->claim($transfer, $payer), $payer, H::payoutAccountFor($payer));

    expect(EmailLog::where('event', EmailEvent::TransferPaid)->count())->toBe(0);
});

// --- Other transfer and balance events ------------------------------------

it('emails the sending agent when the transfer is cancelled, with the reason', function () {
    Mail::fake();
    emailOn();
    $sender = H::sendingAgent(fundCents: 1_000_000);
    $transfer = e2Transfer($sender);

    app(TransferCancellationService::class)->cancel($transfer, makeAdmin(), 'Customer changed their mind');

    $log = EmailLog::where('event', EmailEvent::TransferCancelled)->sole();
    expect($log->to_email)->toBe($sender->email)->and($log->body)->toContain('Customer changed their mind')->toContain($transfer->code);
});

it('emails the agent whose claim expired', function () {
    Mail::fake();
    emailOn();
    $payer = e2Agent($this->branch);
    $transfer = e2Transfer();
    app(PayoutQueueService::class)->claim($transfer, $payer);
    Transfer::query()->whereKey($transfer->id)->update(['claim_expires_at' => now()->subMinute()]);
    EmailLog::query()->delete();

    app(PayoutQueueService::class)->expireDueClaims();

    $log = EmailLog::where('event', EmailEvent::ClaimExpired)->sole();
    expect($log->to_email)->toBe($payer->email)->and($log->body)->toContain($transfer->code);
});

it('emails an agent when their top-up is approved', function () {
    Mail::fake();
    emailOn();
    $agent = H::sendingAgent(fundCents: 0);
    $company = CompanyAccount::create(['name' => 'Office', 'location' => 'europe', 'is_active' => true]);
    $admin = makeAdmin();
    $service = app(BalanceRequestService::class);

    $request = $service->submit(BalanceRequestKind::TopupPaid, $agent, 50_000, $agent, $company);
    $service->approve($request, $admin);

    $log = EmailLog::where('event', EmailEvent::TopupApproved)->sole();
    expect($log->to_email)->toBe($agent->email)->and($log->body)->toContain('$500.00')->toContain($admin->name);
});

// --- Expenses -------------------------------------------------------------

it('emails approvers about a new expense and the creator about the decision', function () {
    Mail::fake();
    emailOn();
    $clerk = User::factory()->create(['branch_id' => $this->branch->id]);
    $clerk->givePermissionTo(['expenses.create', 'expenses.view_own']);
    $approver = makeAdmin();
    $account = CompanyAccount::create(['name' => 'Office', 'location' => 'somalia', 'is_active' => true]);
    $ledger = app(LedgerService::class);
    $ledger->post(JournalType::ManualAdjustment, [
        [$ledger->accountFor($account, LedgerAccountType::Company), 100_000],
        [$ledger->system(LedgerAccountType::Adjustments), -100_000],
    ], null, $approver);
    $service = app(ExpenseService::class);

    $expense = $service->record($clerk, ExpenseType::first(), $account, ['amount_cents' => 25_000, 'expense_date' => today()->toDateString(), 'payee' => 'Landlord'], (string) Str::uuid());

    $submitted = EmailLog::where('event', EmailEvent::ExpenseSubmitted)->get();
    expect($submitted->pluck('to_email')->all())->toBe([$approver->email])   // not the clerk
        ->and($submitted->first()->subject)->toBe("Expense {$expense->number} needs approval — \$250.00")
        ->and($submitted->first()->body)->toContain($clerk->name)->toContain('Landlord')->toContain(route('expenses.show', $expense));

    $service->approve($expense, $approver, 'Matches the invoice');

    $approved = EmailLog::where('event', EmailEvent::ExpenseApproved)->sole();
    expect($approved->to_email)->toBe($clerk->email)->and($approved->body)->toContain('Matches the invoice');

    $second = $service->record($clerk, ExpenseType::first(), $account, ['amount_cents' => 1_000, 'expense_date' => today()->toDateString()], (string) Str::uuid());
    $service->reject($second, $approver, 'No receipt');

    $rejected = EmailLog::where('event', EmailEvent::ExpenseRejected)->sole();
    expect($rejected->to_email)->toBe($clerk->email)->and($rejected->body)->toContain('No receipt');
});

// --- Editable templates -----------------------------------------------------

it('uses the edited wording, and nothing when a template is switched off', function () {
    Mail::fake();
    emailOn();
    $payer = e2Agent($this->branch);
    $template = EmailTemplate::create(['event' => EmailEvent::TransferSent, 'subject' => 'Money for {branch}: {code}', 'body' => 'Salaan {recipient_name}, {amount} is waiting. {link}', 'is_active' => true]);

    $transfer = e2Transfer(null, 12_345);
    $log = EmailLog::where('to_email', $payer->email)->sole();
    expect($log->subject)->toBe("Money for Mogadishu: {$transfer->code}")
        ->and($log->body)->toBe("Salaan {$payer->name}, \$123.45 is waiting. ".route('queue.index'));

    $template->update(['is_active' => false]);
    EmailLog::query()->delete();
    e2Transfer(null, 5_000);

    expect(EmailLog::count())->toBe(0);
});

it('lets an admin edit a template and refuses a typo in a placeholder', function () {
    $admin = makeAdmin();
    $this->actingAs($admin)->get('/setup/email-templates')->assertOk();   // creates the rows
    $template = EmailTemplate::where('event', EmailEvent::TransferSent)->firstOrFail();

    $this->actingAs($admin)->put("/setup/email-templates/{$template->id}", [
        'subject' => 'Transfer {code}', 'body' => 'Hi {recipient_name}, {amout} waiting', 'is_active' => '1',
    ])->assertSessionHasErrors('body');

    $this->actingAs($admin)->put("/setup/email-templates/{$template->id}", [
        'subject' => 'Transfer {code} for {branch}', 'body' => "Hi {recipient_name},\n\n{amount} is waiting.\n{link}", 'is_active' => '1',
    ])->assertRedirect('/setup/email-templates');

    expect($template->fresh()->subject)->toBe('Transfer {code} for {branch}')->and($template->fresh()->is_active)->toBeTrue();

    // Off by leaving the box unticked.
    $this->actingAs($admin)->put("/setup/email-templates/{$template->id}", ['subject' => 's', 'body' => 'b'])->assertRedirect();
    expect($template->fresh()->is_active)->toBeFalse();
});

it('resets a template to the original wording', function () {
    $admin = makeAdmin();
    $this->actingAs($admin)->get('/setup/email-templates');
    $template = EmailTemplate::where('event', EmailEvent::TransferPaid)->firstOrFail();
    $template->update(['subject' => 'changed', 'body' => 'changed']);

    $this->actingAs($admin)->post("/setup/email-templates/{$template->id}/reset")->assertRedirect();

    expect($template->fresh()->subject)->toBe(EmailEvent::TransferPaid->defaultSubject())->and($template->fresh()->body)->toBe(EmailEvent::TransferPaid->defaultBody());
});

it('only lists placeholders an event can fill, and the defaults use only those', function () {
    $renderer = app(EmailTemplateRenderer::class);

    foreach (EmailEvent::cases() as $event) {
        expect($renderer->unknownPlaceholders($event->defaultSubject()."\n".$event->defaultBody(), $event))->toBe([], $event->value)
            ->and($event->defaultBody())->toContain('{recipient_name}');
    }

    // The default texts keep phone numbers out.
    foreach (EmailEvent::cases() as $event) {
        expect($event->defaultBody())->not->toContain('{sender_phone}')->not->toContain('{receiver_phone}');
    }
});

it('previews unsaved text with sample data, escaping anything dangerous', function () {
    $admin = makeAdmin();
    $this->actingAs($admin)->get('/setup/email-templates');
    $template = EmailTemplate::where('event', EmailEvent::TransferSent)->firstOrFail();

    $response = $this->actingAs($admin)->postJson("/setup/email-templates/{$template->id}/preview", [
        'subject' => 'New {code}',
        'body' => "Hi {recipient_name}\n<script>alert(1)</script> {nope}\nSee https://example.com/x?a=1&b=2",
    ])->assertOk();

    $json = $response->json();
    expect($json['subject'])->toBe('New HW-0042')
        ->and($json['html'])->toContain('Hi Farah Omar')->toContain('<br>')
        ->and($json['html'])->not->toContain('<script>')->toContain('&lt;script&gt;')
        ->and($json['html'])->toContain('<a href="https://example.com/x?a=1&amp;b=2"')
        ->and($json['unknown'])->toBe(['nope']);
});

it('never expands a placeholder twice, so a value cannot inject another', function () {
    $renderer = app(EmailTemplateRenderer::class);

    expect($renderer->render('Hi {recipient_name} {code}', EmailEvent::TransferSent, ['recipient_name' => '{code}', 'code' => 'HW-0001']))->toBe('Hi {code} HW-0001');
});

it('escapes values placed in the HTML email', function () {
    emailOn();
    $log = EmailLog::create(['event' => EmailEvent::TransferSent, 'to_email' => 'x@example.com', 'subject' => 's', 'body' => 'Name: <b>Evil</b> & co', 'status' => 'queued']);

    $html = (new TemplatedMail($log))->render();

    expect($html)->toContain('Name: &lt;b&gt;Evil&lt;/b&gt; &amp; co')->not->toContain('<b>Evil</b>');
});

// --- Failure handling -------------------------------------------------------

it('records a failed send with the reason and never breaks the money operation', function () {
    emailOn();
    $payer = e2Agent($this->branch);
    Mail::shouldReceive('purge')->andReturnNull();
    Mail::shouldReceive('mailer')->andThrow(new RuntimeException('Failed to authenticate on SMTP server with username "company@gmail.com" using the following authenticators: LOGIN. Authenticator LOGIN returned Expected response code "250" but got code "535", with message "535-5.7.8 Username and Password not accepted".'));

    $transfer = e2Transfer();

    expect($transfer->status->value)->toBe('queued');   // the transfer itself is fine

    $log = EmailLog::where('to_email', $payer->email)->sole();
    expect($log->status)->toBe(EmailLog::FAILED)
        ->and($log->error)->toContain('App Password')->toContain('535');
});

it('retries a failed email from the log', function () {
    emailOn();
    $log = EmailLog::create(['event' => EmailEvent::TransferSent, 'to_email' => 'x@example.com', 'subject' => 's', 'body' => 'b', 'status' => EmailLog::FAILED, 'error' => 'boom', 'attempts' => 3]);

    Mail::fake();
    $this->actingAs(makeAdmin())->post("/setup/email-log/{$log->id}/retry")->assertRedirect();

    expect($log->fresh()->status)->toBe(EmailLog::SENT)->and($log->fresh()->error)->toBeNull()->and($log->fresh()->sent_at)->not->toBeNull();
    Mail::assertSent(TemplatedMail::class, fn ($m) => $m->hasTo('x@example.com'));

    // A sent one cannot be retried.
    $this->actingAs(makeAdmin())->post("/setup/email-log/{$log->id}/retry")->assertSessionHasErrors('retry');
});

it('skips a queued email if email was switched off meanwhile', function () {
    Mail::fake();
    $log = EmailLog::create(['event' => EmailEvent::TransferSent, 'to_email' => 'x@example.com', 'subject' => 's', 'body' => 'b', 'status' => EmailLog::QUEUED]);

    (new SendEmailLog($log->id))->handle();

    expect($log->fresh()->status)->toBe(EmailLog::SKIPPED);
    Mail::assertNothingSent();
});

it('marks the log failed when all attempts are used', function () {
    $log = EmailLog::create(['event' => EmailEvent::TransferSent, 'to_email' => 'x@example.com', 'subject' => 's', 'body' => 'b', 'status' => EmailLog::QUEUED]);

    (new SendEmailLog($log->id))->failed(new RuntimeException('Connection could not be established with host "smtp.gmail.com"'));

    expect($log->fresh()->status)->toBe(EmailLog::FAILED)->and($log->fresh()->error)->toContain('Could not reach the mail server');
    expect((new SendEmailLog($log->id))->tries)->toBe(3)->and((new SendEmailLog($log->id))->backoff())->toBe([60, 300]);
});

// --- Settings screen ---------------------------------------------------------

it('keeps the Gmail password away from admins but lets them edit the wording', function () {
    $admin = makeAdmin();
    $super = makeSuperAdmin();

    $this->actingAs($admin)->get('/setup/email')->assertForbidden();
    $this->actingAs($admin)->put('/setup/email', [])->assertForbidden();
    $this->actingAs($admin)->post('/setup/email/test', [])->assertForbidden();
    $this->actingAs($admin)->get('/setup/email-templates')->assertOk()->assertDontSee('/setup/email"', false);
    $this->actingAs($admin)->get('/setup/email-log')->assertOk();
    $this->actingAs($super)->get('/setup/email')->assertOk();

    $agent = H::sendingAgent(fundCents: 0);
    foreach (['/setup/email', '/setup/email-templates', '/setup/email-log'] as $url) {
        $this->actingAs($agent)->get($url)->assertForbidden();
    }
});

it('saves the account with the password encrypted, and never shows it again', function () {
    $super = makeSuperAdmin();

    $this->actingAs($super)->put('/setup/email', [
        'email_enabled' => '1', 'email_host' => 'smtp.gmail.com', 'email_port' => 587,
        'email_username' => 'company@gmail.com', 'email_password' => 'abcd efgh ijkl mnop', 'email_from_name' => 'Blue Star Remit',
    ])->assertSessionHasNoErrors();

    $stored = (string) Settings::get('email.password');
    expect($stored)->not->toContain('abcd')->and($stored)->not->toBe('')
        ->and(EmailSettings::current()->password)->toBe('abcdefghijklmnop')   // spaces stripped
        ->and(EmailSettings::enabled())->toBeTrue()
        ->and(EmailSettings::current()->fromName)->toBe('Blue Star Remit');

    $html = $this->actingAs($super)->get('/setup/email')->assertOk()->getContent();
    expect($html)->not->toContain('abcdefghijklmnop')->not->toContain($stored)->toContain('One is saved');

    // Blank keeps it; a new one replaces it.
    $this->actingAs($super)->put('/setup/email', ['email_enabled' => '1', 'email_host' => 'smtp.gmail.com', 'email_port' => 587, 'email_username' => 'company@gmail.com'])->assertSessionHasNoErrors();
    expect(EmailSettings::current()->password)->toBe('abcdefghijklmnop');

    $this->actingAs($super)->put('/setup/email', ['email_enabled' => '1', 'email_host' => 'smtp.gmail.com', 'email_port' => 587, 'email_username' => 'company@gmail.com', 'email_password' => 'zzzzzzzzzzzzzzzz']);
    expect(EmailSettings::current()->password)->toBe('zzzzzzzzzzzzzzzz');
});

it('will not switch email on without an address and a password', function () {
    $this->actingAs(makeSuperAdmin())->put('/setup/email', ['email_enabled' => '1', 'email_host' => 'smtp.gmail.com', 'email_port' => 587])
        ->assertSessionHasErrors('email_username');

    expect(EmailSettings::enabled())->toBeFalse();
});

it('points the hawala mailer at the saved account', function () {
    emailOn();
    Settings::set('email.from_name', 'Blue Star Remit');

    EmailSettings::current()->mailer();

    expect(config('mail.mailers.hawala.host'))->toBe('smtp.gmail.com')
        ->and(config('mail.mailers.hawala.port'))->toBe(587)
        ->and(config('mail.mailers.hawala.username'))->toBe('company@gmail.com')
        ->and(config('mail.mailers.hawala.password'))->toBe('abcdefghijklmnop')
        ->and(config('mail.from.address'))->toBe('company@gmail.com')
        ->and(config('mail.from.name'))->toBe('Blue Star Remit');
});

it('sends a test email with what is typed in the form', function () {
    Mail::fake();
    $super = makeSuperAdmin();

    $this->actingAs($super)->post('/setup/email/test', [
        'test_to' => 'me@example.com', 'email_username' => 'typed@gmail.com', 'email_password' => 'typedpasswordtyped',
    ])->assertSessionHas('status');

    Mail::assertSent(TemplatedMail::class, fn ($m) => $m->hasTo('me@example.com') && str_contains($m->log->subject, 'Test email'));
    expect(config('mail.mailers.hawala.username'))->toBe('typed@gmail.com');
});

it('shows the real error when the test fails, in plain words', function () {
    $super = makeSuperAdmin();
    Mail::shouldReceive('purge')->andReturnNull();
    Mail::shouldReceive('mailer')->andThrow(new RuntimeException('Expected response code "250" but got code "535", with message "535-5.7.8 Username and Password not accepted"'));

    $this->actingAs($super)->post('/setup/email/test', [
        'test_to' => 'me@example.com', 'email_username' => 'typed@gmail.com', 'email_password' => 'wrongwrongwrongx',
    ])->assertSessionHasErrors('test_to');

    expect(session('errors')->first('test_to'))->toContain('App Password')->toContain('535');
});

it('asks for the account before a test can be sent', function () {
    $this->actingAs(makeSuperAdmin())->post('/setup/email/test', ['test_to' => 'me@example.com'])->assertSessionHasErrors('test_to');
});

it('sends a template test with sample data through the account', function () {
    Mail::fake();
    emailOn();
    $admin = makeAdmin();
    $this->actingAs($admin)->get('/setup/email-templates');
    $template = EmailTemplate::where('event', EmailEvent::TransferPaid)->firstOrFail();

    $this->actingAs($admin)->post("/setup/email-templates/{$template->id}/test", ['test_to' => 'me@example.com'])->assertSessionHas('status');

    Mail::assertSent(TemplatedMail::class, fn ($m) => $m->hasTo('me@example.com')
        && str_starts_with($m->log->subject, '[Test] Transfer HW-0042 has been paid')
        && str_contains($m->log->body, 'Hello Farah Omar'));
    expect(EmailLog::count())->toBe(0); // tests are not mixed into the real log
});

// --- Log, warnings and the per-user switch -----------------------------------

it('lists and filters the sent emails', function () {
    $admin = makeAdmin();
    EmailLog::create(['event' => EmailEvent::TransferSent, 'to_email' => 'a@example.com', 'to_name' => 'Alpha', 'subject' => 'SUBJ-ONE', 'body' => 'b', 'status' => EmailLog::SENT, 'sent_at' => now()]);
    EmailLog::create(['event' => EmailEvent::ExpenseSubmitted, 'to_email' => 'b@example.com', 'to_name' => 'Beta', 'subject' => 'SUBJ-TWO', 'body' => 'b', 'status' => EmailLog::FAILED, 'error' => 'Gmail said no']);

    $this->actingAs($admin)->get('/setup/email-log')->assertOk()->assertSee('SUBJ-ONE')->assertSee('SUBJ-TWO')->assertSee('Gmail said no')->assertSee('Retry');
    $this->actingAs($admin)->get('/setup/email-log?status=failed')->assertSee('SUBJ-TWO')->assertDontSee('SUBJ-ONE');
    $this->actingAs($admin)->get('/setup/email-log?event=transfer_sent')->assertSee('SUBJ-ONE')->assertDontSee('SUBJ-TWO');
    $this->actingAs($admin)->get('/setup/email-log?search=Beta')->assertSee('SUBJ-TWO')->assertDontSee('SUBJ-ONE');
});

it('warns when close to Gmail’s daily limit', function () {
    $super = makeSuperAdmin();
    $this->actingAs($super)->get('/setup/email')->assertDontSee('Close to the daily limit');

    foreach (range(1, 400) as $i) {
        EmailLog::create(['event' => EmailEvent::TransferSent, 'to_email' => "u{$i}@example.com", 'subject' => 's', 'body' => 'b', 'status' => EmailLog::SENT, 'sent_at' => now()->subHours(2)]);
    }
    // Older than a day does not count.
    EmailLog::create(['event' => EmailEvent::TransferSent, 'to_email' => 'old@example.com', 'subject' => 's', 'body' => 'b', 'status' => EmailLog::SENT, 'sent_at' => now()->subDays(2)]);

    expect(EmailLog::sentInLastDay())->toBe(400);
    $this->actingAs($super)->get('/setup/email')->assertSee('Close to the daily limit');
    $this->actingAs($super)->get('/setup/email-log')->assertSee('Close to the daily limit');
});

it('lets email notifications be switched off per user', function () {
    $super = makeSuperAdmin();
    $branch = $this->branch;

    $this->actingAs($super)->post('/users', [
        'name' => 'No Mail', 'email' => 'nomail@example.com', 'password' => 'Str0ng!Passw0rd#', 'type' => 'staff',
        'branch_id' => $branch->id, 'role' => 'admin', 'email_notifications' => '0',
    ])->assertRedirect('/users');

    $user = User::where('email', 'nomail@example.com')->firstOrFail();
    expect($user->email_notifications)->toBeFalse();

    // Default is on when the form says nothing.
    $this->actingAs($super)->post('/users', [
        'name' => 'Mail', 'email' => 'mail@example.com', 'password' => 'Str0ng!Passw0rd#', 'type' => 'staff',
        'branch_id' => $branch->id, 'role' => 'admin',
    ]);
    expect(User::where('email', 'mail@example.com')->first()->email_notifications)->toBeTrue();

    $payload = ['name' => 'No Mail', 'email' => 'nomail@example.com', 'type' => 'staff', 'branch_id' => $branch->id, 'role' => 'admin'];
    $this->actingAs($super)->put("/users/{$user->id}", $payload + ['email_notifications' => '1'])->assertSessionHasNoErrors();
    expect($user->fresh()->email_notifications)->toBeTrue();

    // An older form that does not send the field leaves it alone.
    User::query()->whereKey($user->id)->update(['email_notifications' => false]);
    $this->actingAs($super)->put("/users/{$user->id}", $payload)->assertSessionHasNoErrors();
    expect($user->fresh()->email_notifications)->toBeFalse();

    $this->actingAs($super)->get("/users/{$user->id}/edit")->assertOk()->assertSee('Send this person email notifications');
});

it('shows Email on the Setup page to those who may use it', function () {
    $this->actingAs(makeAdmin())->get('/setup')->assertOk()->assertSee(route('setup.email-templates.index'), false);
    $this->actingAs(makeSuperAdmin())->get('/setup')->assertOk()->assertSee('Email');
});

it('creates a template for every event, once', function () {
    $this->seed(EmailTemplatesSeeder::class);
    $this->seed(EmailTemplatesSeeder::class);

    expect(EmailTemplate::count())->toBe(count(EmailEvent::cases()))->and(EmailTemplate::count())->toBe(9);
});

it('leaves the transfer, expense and balance flows working with email off', function () {
    $transfer = e2Transfer();
    expect($transfer->code)->toBe('HW-0001');
});
