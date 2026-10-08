<?php

namespace App\Support;

use App\Reports\ReportRegistry;
use Illuminate\Support\Facades\Auth;

/**
 * Where a dashboard card should lead: the report behind the number, or
 * null when the signed-in user may not open that report (the card then
 * renders as plain text rather than a dead link).
 */
class ReportLink
{
    /**
     * @param  array<string, scalar>  $params  report filters (agent_id, status, …)
     * @param  bool  $allTime  for "right now" figures (uncollected, waiting) whose
     *                         rows may be older than the default current month
     */
    public static function to(string $key, array $params = [], bool $allTime = false): ?string
    {
        $report = app(ReportRegistry::class)->find($key);

        if ($report === null || ! Auth::user()?->can($report->permission())) {
            return null;
        }

        if ($allTime) {
            $params = ['from' => '2000-01-01', 'to' => today()->toDateString()] + $params;
        }

        return route('reports.show', ['report' => $key] + $params);
    }

    public static function today(string $key, array $params = []): ?string
    {
        return self::to($key, ['from' => today()->toDateString(), 'to' => today()->toDateString()] + $params);
    }
}
