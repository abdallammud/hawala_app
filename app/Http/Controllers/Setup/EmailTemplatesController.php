<?php

namespace App\Http\Controllers\Setup;

use App\Enums\EmailEvent;
use App\Http\Controllers\Controller;
use App\Mail\TemplatedMail;
use App\Models\EmailLog;
use App\Models\EmailTemplate;
use App\Services\Email\EmailSettings;
use App\Services\Email\EmailTemplateRenderer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Throwable;

/** Edit the subject and text of each email, with a live preview and a test send. */
class EmailTemplatesController extends Controller
{
    public function __construct(private EmailTemplateRenderer $renderer) {}

    public function index(): View
    {
        $this->authorize('setup.email');

        $this->ensureAll();

        return view('setup.email.templates', [
            'templates' => EmailTemplate::query()->get()->keyBy(fn (EmailTemplate $t) => $t->event->value),
            'events' => EmailEvent::cases(),
            'emailReady' => EmailSettings::ready(),
        ]);
    }

    public function edit(Request $request, EmailTemplate $emailTemplate): View
    {
        $this->authorize('setup.email');

        return view('setup.email.template-edit', [
            'template' => $emailTemplate,
            'placeholders' => $emailTemplate->event->placeholders(),
            'preview' => $this->preview($emailTemplate->event, $emailTemplate->subject, $emailTemplate->body),
            'testAddress' => $request->user()->email,
        ]);
    }

    public function update(Request $request, EmailTemplate $emailTemplate): RedirectResponse
    {
        $this->authorize('setup.email');

        $validated = $this->validateText($request, $emailTemplate->event);

        $emailTemplate->update([
            'subject' => trim($validated['subject']),
            'body' => str_replace("\r\n", "\n", trim($validated['body'])),
            'is_active' => $request->boolean('is_active'),
        ]);

        return redirect()->route('setup.email-templates.index')->with('status', __('Template saved.'));
    }

    public function reset(EmailTemplate $emailTemplate): RedirectResponse
    {
        $this->authorize('setup.email');

        $emailTemplate->update([
            'subject' => $emailTemplate->event->defaultSubject(),
            'body' => $emailTemplate->event->defaultBody(),
        ]);

        return redirect()->route('setup.email-templates.edit', $emailTemplate)->with('status', __('Back to the original wording.'));
    }

    /** Live preview while typing: the unsaved subject and text, filled with sample data. */
    public function previewText(Request $request, EmailTemplate $emailTemplate): JsonResponse
    {
        $this->authorize('setup.email');

        $request->validate(['subject' => ['nullable', 'string', 'max:500'], 'body' => ['nullable', 'string', 'max:10000']]);

        return response()->json($this->preview($emailTemplate->event, (string) $request->input('subject'), (string) $request->input('body')));
    }

    /** Sends the sample version of this template to an address, through the real account. */
    public function test(Request $request, EmailTemplate $emailTemplate): RedirectResponse
    {
        $this->authorize('setup.email');

        $validated = $request->validate(['test_to' => ['required', 'email', 'max:255']]);
        $account = EmailSettings::current();

        if (! $account->configured()) {
            return back()->withErrors(['test_to' => __('Set up the Gmail account first (Email → Connection).')]);
        }

        $event = $emailTemplate->event;
        $values = $this->renderer->sampleValues($event);

        $message = new EmailLog([
            'event' => $event,
            'subject' => '['.__('Test').'] '.$this->renderer->renderSubject($emailTemplate->subject, $event, $values),
            'body' => $this->renderer->render($emailTemplate->body, $event, $values),
        ]);

        try {
            $account->mailer()->to($validated['test_to'])->send(new TemplatedMail($message));
        } catch (Throwable $e) {
            return back()->withErrors(['test_to' => __('Could not send: :error', ['error' => EmailSettings::explain($e)])]);
        }

        return back()->with('status', __('Test email sent to :to.', ['to' => $validated['test_to']]));
    }

    /** @return array{subject: string, html: string, unknown: string[]} */
    private function preview(EmailEvent $event, string $subject, string $body): array
    {
        $values = $this->renderer->sampleValues($event);

        return [
            'subject' => $this->renderer->renderSubject($subject, $event, $values),
            'html' => $this->renderer->toHtml($this->renderer->render($body, $event, $values)),
            'unknown' => $this->renderer->unknownPlaceholders($subject."\n".$body, $event),
        ];
    }

    /** A typo like {amout} would go out as a blank, so it is refused when saving. */
    private function validateText(Request $request, EmailEvent $event): array
    {
        $validated = $request->validate([
            'subject' => ['required', 'string', 'max:200'],
            'body' => ['required', 'string', 'max:5000'],
        ]);

        $unknown = $this->renderer->unknownPlaceholders($validated['subject']."\n".$validated['body'], $event);

        if ($unknown !== []) {
            throw ValidationException::withMessages([
                'body' => __('Unknown placeholder: :names. Use only the ones listed on the right.', ['names' => collect($unknown)->map(fn ($n) => '{'.$n.'}')->implode(', ')]),
            ]);
        }

        return $validated;
    }

    /** A template row for every event, created from the defaults when missing. */
    private function ensureAll(): void
    {
        foreach (EmailEvent::cases() as $event) {
            EmailTemplate::query()->firstOrCreate(
                ['event' => $event],
                ['subject' => $event->defaultSubject(), 'body' => $event->defaultBody(), 'is_active' => true],
            );
        }
    }
}
