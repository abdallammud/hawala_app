<?php

namespace App\Http\Controllers\Setup;

use App\Enums\SmsEvent;
use App\Http\Controllers\Controller;
use App\Http\Requests\Setup\UpdateSmsTemplateRequest;
use App\Models\SmsTemplate;
use App\Services\Sms\SmsDeliveryException;
use App\Services\Sms\SmsSender;
use App\Services\Sms\SmsTemplateRenderer;
use App\Support\Settings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SmsTemplatesController extends Controller
{
    public function __construct(private SmsTemplateRenderer $renderer) {}

    public function index(): View
    {
        $this->authorize('setup.sms');

        $templates = SmsTemplate::query()->get()->keyBy(fn (SmsTemplate $t) => $t->event->value);

        return view('setup.sms-templates.index', [
            'events' => SmsEvent::cases(),
            'templates' => $templates,
        ]);
    }

    public function edit(SmsTemplate $smsTemplate): View
    {
        $this->authorize('setup.sms');

        return view('setup.sms-templates.edit', [
            'template' => $smsTemplate,
            'placeholders' => SmsTemplateRenderer::PLACEHOLDERS,
            'preview' => $this->renderer->render($smsTemplate->body, $this->renderer->sampleValues()),
        ]);
    }

    public function update(UpdateSmsTemplateRequest $request, SmsTemplate $smsTemplate): RedirectResponse
    {
        $data = $request->validated();

        // Recipient toggles are checkboxes: absent means off.
        foreach (['to_sender', 'to_receiver', 'to_sending_agent', 'to_payout_agent', 'is_active'] as $flag) {
            $data[$flag] = $request->boolean($flag);
        }

        // A template with no recipients would silently send nothing.
        if ($data['is_active'] && ! collect(['to_sender', 'to_receiver', 'to_sending_agent', 'to_payout_agent'])->contains(fn ($f) => $data[$f])) {
            return back()
                ->withInput()
                ->withErrors(['to_sender' => __('Choose at least one recipient before switching this template on.')]);
        }

        $smsTemplate->update($data);

        return redirect()
            ->route('setup.sms-templates.index')
            ->with('status', __('Template saved.'));
    }

    /** "Send test SMS" (BUILD.md §13) — proves the gateway works before going live. */
    public function test(Request $request, SmsTemplate $smsTemplate, SmsSender $sender): RedirectResponse
    {
        $this->authorize('setup.sms');

        $validated = $request->validate([
            'test_phone' => ['required', 'string', 'max:32'],
        ]);

        $message = $this->renderer->render($smsTemplate->body, $this->renderer->sampleValues());

        try {
            $response = $sender->sendTest($validated['test_phone'], $message);
        } catch (SmsDeliveryException $e) {
            return back()->withErrors(['test_phone' => __('Could not send: :error', ['error' => $e->getMessage()])]);
        }

        return back()->with('status', __('Test sent via the :driver driver. Response: :response', [
            'driver' => (string) Settings::get('sms.driver', 'log'),
            'response' => $response,
        ]));
    }
}
