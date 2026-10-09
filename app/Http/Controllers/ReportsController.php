<?php

namespace App\Http\Controllers;

use App\Enums\UserType;
use App\Models\AccountType;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\User;
use App\Reports\AgentStatementReport;
use App\Reports\Report;
use App\Reports\ReportFilters;
use App\Reports\ReportRegistry;
use App\Reports\TransfersReport;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportsController extends Controller
{
    public function __construct(private ReportRegistry $registry) {}

    public function index(Request $request): View
    {
        $reports = $this->registry->visibleTo($request->user());

        abort_if($reports->isEmpty(), 403);

        return view('reports.index', ['reports' => $reports]);
    }

    public function show(Request $request, string $report): View
    {
        [$definition, $filters, $rows] = $this->run($request, $report);

        return view('reports.show', [
            'report' => $definition,
            'filters' => $filters,
            'rows' => $rows,
            'totals' => $definition->totals($rows),
            'options' => $this->filterOptions($request->user()),
        ]);
    }

    public function export(Request $request, string $report): StreamedResponse
    {
        [$definition, $filters, $rows] = $this->run($request, $report);

        $filename = sprintf(
            '%s-%s-to-%s.csv',
            $definition->key(),
            $filters->from->toDateString(),
            $filters->to->toDateString()
        );

        $columns = $definition->columns();
        $moneyColumns = $definition->moneyColumns();

        return response()->streamDownload(function () use ($rows, $columns, $moneyColumns) {
            $out = fopen('php://output', 'w');

            fputcsv($out, array_values($columns));

            foreach ($rows as $row) {
                fputcsv($out, collect($columns)->keys()->map(function (string $key) use ($row, $moneyColumns) {
                    $value = $row[$key] ?? '';

                    // Money exports as a plain decimal so spreadsheets read it
                    // as a number rather than text.
                    return in_array($key, $moneyColumns, true)
                        ? number_format(((int) $value) / 100, 2, '.', '')
                        : $value;
                })->all());
            }

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    /** @return array{0: Report, 1: ReportFilters, 2: Collection} */
    private function run(Request $request, string $key): array
    {
        $report = $this->registry->find($key);

        abort_if($report === null, 404);
        abort_unless($request->user()->can($report->permission()), 403);

        $filters = ReportFilters::fromRequest($request);

        return [$report, $filters, $report->rows($filters, $request->user())];
    }

    private function filterOptions(User $user): array
    {
        return [
            'branches' => Branch::query()->orderBy('name')->pluck('name', 'id'),
            // Someone who can't see everyone's transfers gets only themselves
            // in the list — the others' names aren't theirs to browse.
            'agents' => User::query()
                ->whereIn('type', [UserType::SendingAgent, UserType::PayoutAgent, UserType::DualAgent, UserType::Staff])
                ->when(! $user->can('transfers.view_all'), fn ($q) => $q->whereKey($user->id))
                ->orderBy('name')
                ->pluck('name', 'id'),
            'customers' => Customer::query()
                ->visibleTo($user)
                ->orderBy('name')
                ->limit(500)
                ->get()
                ->mapWithKeys(fn (Customer $c) => [$c->id => $c->name.' · '.$c->phoneLabel()]),
            'statuses' => TransfersReport::statusOptions(),
            'accountTypes' => AgentStatementReport::accountTypeOptions(),
            'payoutMethods' => AccountType::query()->orderBy('sort')->pluck('name', 'name'),
            'groupBy' => ['day' => __('By day'), 'month' => __('By month')],
        ];
    }
}
