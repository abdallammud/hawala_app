<?php

namespace App\Reports;

use App\Models\User;
use Illuminate\Support\Collection;

/**
 * One report. Subclasses describe their columns and produce rows; the
 * controller handles filters, display and CSV export uniformly.
 *
 * Money values are returned as integer cents and listed in moneyColumns()
 * so the view can format them and the export can write plain decimals that
 * a spreadsheet will parse as numbers.
 */
abstract class Report
{
    /** URL slug, e.g. "transfers". */
    abstract public function key(): string;

    abstract public function title(): string;

    abstract public function description(): string;

    /** Permission required to run it (BUILD.md §10). */
    abstract public function permission(): string;

    /** @return array<string, string> column key => heading */
    abstract public function columns(): array;

    /** @return Collection<int, array<string, mixed>> */
    abstract public function rows(ReportFilters $filters, User $user): Collection;

    /** Column keys holding integer cents. */
    public function moneyColumns(): array
    {
        return [];
    }

    /** Column keys to right-align (money and counts). */
    public function numericColumns(): array
    {
        return $this->moneyColumns();
    }

    /** Which filter inputs to render: from, to, branch, agent, status, account_type, group_by. */
    public function availableFilters(): array
    {
        return ['from', 'to'];
    }

    /**
     * Totals row, keyed by column. Defaults to summing every money column,
     * which is right for most reports; override where it isn't.
     *
     * @return array<string, mixed>
     */
    public function totals(Collection $rows): array
    {
        $totals = [];

        foreach ($this->moneyColumns() as $column) {
            $totals[$column] = (int) $rows->sum(fn (array $row) => $row[$column] ?? 0);
        }

        return $totals;
    }

    /**
     * Status choices when this report's status filter means something other
     * than a transfer status. Null = use the transfer statuses.
     *
     * @return array<string, string>|null
     */
    public function statusChoices(): ?array
    {
        return null;
    }

    /** What the status filter reads when nothing was chosen (null = "all statuses"). */
    public function defaultStatus(): ?string
    {
        return null;
    }

    /** Label for the totals row; reports whose total is not a plain sum can say what it is. */
    public function totalLabel(Collection $rows): string
    {
        return __('Total');
    }

    /**
     * Extra lines under the table, as [label, cents] pairs (e.g. a total per
     * status). Shown on screen, in print and in the PDF.
     *
     * @return array<int, array{0: string, 1: int}>
     */
    public function summary(Collection $rows): array
    {
        return [];
    }
}
