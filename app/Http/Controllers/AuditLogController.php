<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\LocalTime;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Spatie\Activitylog\Models\Activity;

class AuditLogController extends Controller
{
    public function index(Request $request): View
    {
        $this->authorize('audit.view');

        $activities = Activity::query()
            ->with('causer')
            ->when($request->filled('user_id'), fn ($q) => $q->where('causer_id', $request->integer('user_id')))
            ->when($request->filled('subject_type'), fn ($q) => $q->where('subject_type', $request->string('subject_type')))
            ->when($request->filled('event'), fn ($q) => $q->where('event', $request->string('event')))
            ->when($request->filled('from'), fn ($q) => $q->where('created_at', '>=', LocalTime::dayStart($request->string('from')->toString())))
            ->when($request->filled('to'), fn ($q) => $q->where('created_at', '<=', LocalTime::dayEnd($request->string('to')->toString())))
            ->latest()
            ->paginate(30)
            ->withQueryString();

        $subjectTypes = Activity::query()
            ->select('subject_type')
            ->distinct()
            ->whereNotNull('subject_type')
            ->pluck('subject_type')
            ->mapWithKeys(fn (string $type) => [$type => class_basename($type)])
            ->sort();

        return view('audit.index', [
            'activities' => $activities,
            'users' => User::query()->orderBy('name')->pluck('name', 'id'),
            'subjectTypes' => $subjectTypes,
            'events' => ['created' => __('Created'), 'updated' => __('Updated'), 'deleted' => __('Deleted'), 'approved' => __('Approved'), 'rejected' => __('Rejected'), 'returned' => __('Returned to pending')],
        ]);
    }
}
