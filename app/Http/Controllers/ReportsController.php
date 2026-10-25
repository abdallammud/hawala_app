<?php

namespace App\Http\Controllers;

use App\Enums\UserType;
use App\Models\AccountType;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\ExpenseType;
use App\Models\User;
use App\Reports\AgentStatementReport;
use App\Reports\Report;
use App\Reports\ReportFilters;
use App\Reports\ReportRegistry;
use App\Reports\TransfersReport;
use App\Support\Brand;
use App\Support\Settings;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;
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
            'totalLabel' => $definition->totalLabel($rows),
            'summary' => $definition->summary($rows),
            'options' => $this->filterOptions($request->user()),
        ]);
    }

    /** The same report on a clean page with no menus, opened in a new tab and sent to the printer. */
    public function print(Request $request, string $report): View
    {
        [$definition, $filters, $rows] = $this->run($request, $report);

        return view('reports.document', $this->documentData($request, $definition, $filters, $rows) + ['mode' => 'print']);
    }

    /** The same document as a PDF download. */
    public function pdf(Request $request, string $report): Response|RedirectResponse
    {
        [$definition, $filters, $rows] = $this->run($request, $report);

        // dompdf holds the whole document in memory, and shared hosting has little of it.
        $max = $this->pdfRowLimit();

        if ($rows->count() > $max) {
            return back()->withErrors(['pdf' => __('This report has :count rows — too many for a PDF. Narrow the filters (to :max rows or fewer), or use Export CSV.', ['count' => number_format($rows->count()), 'max' => number_format($max)])]);
        }

        $data = $this->documentData($request, $definition, $filters, $rows) + ['mode' => 'pdf'];

        // Nine columns or more do not fit across a portrait page.
        $orientation = count($definition->columns()) > 6 ? 'landscape' : 'portrait';

        $pdf = Pdf::loadView('reports.document', $data)->setPaper('a4', $orientation);

        // Page numbers go on after layout, when the page count is known. dompdf's
        // in-template PHP scripts stay switched off: nothing in a document built
        // from user-entered data should ever be able to run code.
        $pdf->render();
        $dompdf = $pdf->getDomPDF();
        $canvas = $dompdf->getCanvas();
        $font = $dompdf->getFontMetrics()->getFont('Helvetica');
        $grey = [0.42, 0.45, 0.5];
        $canvas->page_text(30, $canvas->get_height() - 30, $data['companyName'].' · '.$definition->title(), $font, 8, $grey);
        $canvas->page_text($canvas->get_width() - 90, $canvas->get_height() - 30, __('Page').' {PAGE_NUM} / {PAGE_COUNT}', $font, 8, $grey);

        return $pdf->download(sprintf('%s-%s-to-%s.pdf', $definition->key(), $filters->fromDate(), $filters->toDate()));
    }

    public function export(Request $request, string $report): StreamedResponse
    {
        [$definition, $filters, $rows] = $this->run($request, $report);

        $filename = sprintf(
            '%s-%s-to-%s.csv',
            $definition->key(),
            $filters->fromDate(),
            $filters->toDate()
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

    /**
     * How many rows a PDF may have on this server. dompdf needs roughly 60 MB
     * plus 0.25 MB per row (measured on a nine-column report), so the limit
     * follows PHP's memory_limit instead of letting a big report die with a
     * fatal error. Capped by hawala.reports.pdf_max_rows.
     */
    private function pdfRowLimit(): int
    {
        $cap = (int) config('hawala.reports.pdf_max_rows', 2000);
        $limit = ini_parse_quantity((string) ini_get('memory_limit'));

        if ($limit <= 0) { // -1: no limit
            return $cap;
        }

        $room = ($limit * 0.8 - 60 * 1048576) / (0.25 * 1048576);

        return min($cap, max(50, (int) floor($room)));
    }

    /** Everything the shared print / PDF template needs, in one place so no report has its own copy. */
    private function documentData(Request $request, Report $report, ReportFilters $filters, Collection $rows): array
    {
        $tz = Settings::get('general.timezone', 'Africa/Mogadishu');

        return [
            'report' => $report,
            'rows' => $rows,
            'totals' => $report->totals($rows),
            'totalLabel' => $report->totalLabel($rows),
            'summary' => $report->summary($rows),
            'filtersUsed' => $this->describeFilters($report, $filters, $this->filterOptions($request->user())),
            'companyName' => Brand::companyName(),
            'logo' => Brand::logoDataUri(),
            'generatedAt' => now()->timezone($tz)->format('Y-m-d H:i'),
            'generatedBy' => $request->user()->name,
        ];
    }

    /**
     * The filters in force, in words, for the header of a printed report.
     *
     * @return array<int, array{0: string, 1: string}> [label, value] pairs
     */
    private function describeFilters(Report $report, ReportFilters $f, array $options): array
    {
        $lines = [];
        $statuses = $report->statusChoices() ?? $options['statuses'];

        foreach ($report->availableFilters() as $filter) {
            $line = match ($filter) {
                'from' => [__('Period'), $f->fromDate().' '.__('to').' '.$f->toDate()],
                'branch' => [__('Branch'), $options['branches'][$f->branchId] ?? __('All branches')],
                'agent' => [__('Agent'), $options['agents'][$f->agentId] ?? __('All agents')],
                'expense_type' => [__('Category'), $options['expenseTypes'][$f->expenseTypeId] ?? __('All categories')],
                'customer' => [__('Customer'), $options['customers'][$f->customerId] ?? '—'],
                'status' => [__('Status'), $statuses[$f->status ?? $report->defaultStatus()] ?? __('All statuses')],
                'account_type' => [__('Balance'), $options['accountTypes'][$f->accountType ?? 'agent_send'] ?? '—'],
                'group_by' => [__('Group by'), $options['groupBy'][$f->groupBy ?? 'day'] ?? '—'],
                default => null, // 'to' is part of the period
            };

            if ($line) {
                $lines[] = $line;
            }
        }

        return $lines;
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
            'expenseTypes' => ExpenseType::query()->orderBy('sort')->orderBy('name')->pluck('name', 'id'),
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
