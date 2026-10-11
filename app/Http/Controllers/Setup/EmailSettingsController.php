<?php

namespace App\Http\Controllers\Setup;

use App\Http\Controllers\Controller;
use App\Mail\TemplatedMail;
use App\Models\EmailLog;
use App\Services\Email\EmailSettings;
use App\Support\Settings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Throwable;

/**
 * The company Gmail account: address, app password, and a button that sends a
 * real test email so the credentials are proven before anything depends on them.
 */
class EmailSettingsController extends Controller
{
    /** Gmail's own ceiling for a normal account; we warn well before it. */
    public const DAILY_WARNING = 400;

    public function edit(Request $request): View
    {
        $this->authorize('setup.email_settings');

        $current = EmailSettings::current();

        return view('setup.email.settings', [
            'enabled' => EmailSettings::enabled(),
            'host' => $current->host,
            'port' => $current->port,
            'username' => $current->username,
            'fromName' => (string) Settings::get('email.from_name', ''),
            'replyTo' => $current->replyTo,
            // The password never goes back to the browser, only whether one is saved.
            'passwordSaved' => $current->password !== '',
            'sentToday' => EmailLog::sentInLastDay(),
            'dailyWarning' => self::DAILY_WARNING,
            'testAddress' => $request->user()->email,
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $this->authorize('setup.email_settings');

        $validated = $request->validate([
            'email_enabled' => ['boolean'],
            'email_host' => ['required', 'string', 'max:255'],
            'email_port' => ['required', 'integer', 'min:1', 'max:65535'],
            'email_username' => ['nullable', 'email', 'max:255'],
            'email_password' => ['nullable', 'string', 'max:255'],
            'email_from_name' => ['nullable', 'string', 'max:100'],
            'email_reply_to' => ['nullable', 'email', 'max:255'],
        ]);

        $enabled = $request->boolean('email_enabled');
        $username = trim((string) ($validated['email_username'] ?? ''));
        // Google shows app passwords in four groups with spaces; they are not part of it.
        $newPassword = str_replace(' ', '', (string) ($validated['email_password'] ?? ''));

        if ($enabled && ($username === '' || ($newPassword === '' && ! EmailSettings::passwordSaved()))) {
            throw ValidationException::withMessages([
                'email_username' => __('Enter the Gmail address and its app password before switching email on.'),
            ]);
        }

        $by = $request->user()->id;

        Settings::set('email.enabled', $enabled, $by);
        Settings::set('email.host', trim($validated['email_host']), $by);
        Settings::set('email.port', (int) $validated['email_port'], $by);
        Settings::set('email.username', $username, $by);
        Settings::set('email.from_name', trim((string) ($validated['email_from_name'] ?? '')), $by);
        Settings::set('email.reply_to', trim((string) ($validated['email_reply_to'] ?? '')), $by);

        // Blank means "keep the saved one".
        if ($newPassword !== '') {
            Settings::set('email.password', EmailSettings::encrypt($newPassword), $by);
        }

        activity()->causedBy($request->user())->log($newPassword !== '' ? 'Email settings changed (new app password)' : 'Email settings changed');

        return back()->with('status', __('Email settings saved.'));
    }

    /** Sends a real message with what is typed in the form (blank boxes use the saved values). */
    public function test(Request $request): RedirectResponse
    {
        $this->authorize('setup.email_settings');

        $validated = $request->validate([
            'test_to' => ['required', 'email', 'max:255'],
            'email_host' => ['nullable', 'string', 'max:255'],
            'email_port' => ['nullable', 'integer', 'min:1', 'max:65535'],
            'email_username' => ['nullable', 'email', 'max:255'],
            'email_password' => ['nullable', 'string', 'max:255'],
        ]);

        $account = EmailSettings::current()->with(
            $validated['email_host'] ?? null,
            isset($validated['email_port']) ? (int) $validated['email_port'] : null,
            $validated['email_username'] ?? null,
            isset($validated['email_password']) ? str_replace(' ', '', $validated['email_password']) : null,
        );

        if (! $account->configured()) {
            return back()->withInput()->withErrors(['test_to' => __('Enter the Gmail address and its app password first.')]);
        }

        $company = Settings::get('general.company_name', config('app.name'));

        $message = new EmailLog([
            'subject' => __('Test email from :company', ['company' => $company]),
            'body' => __("This is a test message from the :company remittance system.\n\nIf you can read this, the Gmail account is set up correctly and the system can send email.", ['company' => $company]),
        ]);

        try {
            $account->mailer()->to($validated['test_to'])->send(new TemplatedMail($message));
        } catch (Throwable $e) {
            return back()->withInput()->withErrors(['test_to' => __('Could not send: :error', ['error' => EmailSettings::explain($e)])]);
        }

        return back()->withInput()->with('status', __('Test email sent to :to. Check that inbox (and its spam folder).', ['to' => $validated['test_to']]));
    }
}
