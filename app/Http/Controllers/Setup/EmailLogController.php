<?php

namespace App\Http\Controllers\Setup;

use App\Enums\EmailEvent;
use App\Http\Controllers\Controller;
use App\Jobs\SendEmailLog;
use App\Models\EmailLog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Every email the system tried to send, with the reason when one failed. */
class EmailLogController extends Controller
{
    public function index(Request $request): View
    {
        $this->authorize('setup.email');

        $logs = EmailLog::query()
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('event'), fn ($q) => $q->where('event', $request->string('event')))
            ->when($request->filled('search'), function ($q) use ($request) {
                $s = $request->string('search')->toString();
                $q->where(fn ($q) => $q->where('to_email', 'like', "%{$s}%")->orWhere('to_name', 'like', "%{$s}%")->orWhere('subject', 'like', "%{$s}%"));
            })
            ->latest('id')
            ->paginate(25)
            ->withQueryString();

        return view('setup.email.log', [
            'logs' => $logs,
            'events' => collect(EmailEvent::cases())->mapWithKeys(fn (EmailEvent $e) => [$e->value => $e->label()])->all(),
            'statuses' => [EmailLog::QUEUED => __('Waiting'), EmailLog::SENT => __('Sent'), EmailLog::FAILED => __('Failed'), EmailLog::SKIPPED => __('Skipped')],
            'sentToday' => EmailLog::sentInLastDay(),
            'dailyWarning' => EmailSettingsController::DAILY_WARNING,
        ]);
    }

    public function retry(EmailLog $emailLog): RedirectResponse
    {
        $this->authorize('setup.email');

        if (! in_array($emailLog->status, [EmailLog::FAILED, EmailLog::SKIPPED], true)) {
            return back()->withErrors(['retry' => __('Only a failed email can be retried.')]);
        }

        $emailLog->update(['status' => EmailLog::QUEUED, 'error' => null, 'attempts' => 0]);
        SendEmailLog::dispatch($emailLog->id);

        return back()->with('status', __('Queued again. It goes out within a minute.'));
    }
}
