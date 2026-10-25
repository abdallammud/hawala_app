<?php

namespace App\Reports;

use App\Support\LocalTime;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

/**
 * The filter inputs a report can read. Every report gets a date range;
 * the rest are optional and each report declares which it uses.
 */
class ReportFilters
{
    /** `from` / `to` are UTC instants (local day start / end), ready for whereBetween on UTC columns. */
    public function __construct(
        public readonly CarbonImmutable $from,
        public readonly CarbonImmutable $to,
        public readonly ?int $branchId = null,
        public readonly ?int $agentId = null,
        public readonly ?string $status = null,
        public readonly ?string $accountType = null,
        public readonly ?string $groupBy = null,
        public readonly ?int $customerId = null,
        public readonly ?int $expenseTypeId = null,
    ) {}

    public static function fromRequest(Request $request): self
    {
        // Default to the current month — the range a manager asks for most.
        $from = $request->filled('from')
            ? LocalTime::dayStart($request->string('from')->toString())
            : LocalTime::monthStart();

        $to = $request->filled('to')
            ? LocalTime::dayEnd($request->string('to')->toString())
            : LocalTime::dayEnd(LocalTime::now()->toDateString());

        return new self(
            from: $from,
            to: $to,
            branchId: $request->filled('branch_id') ? $request->integer('branch_id') : null,
            agentId: $request->filled('agent_id') ? $request->integer('agent_id') : null,
            status: $request->filled('status') ? $request->string('status')->toString() : null,
            accountType: $request->filled('account_type') ? $request->string('account_type')->toString() : null,
            groupBy: $request->filled('group_by') ? $request->string('group_by')->toString() : null,
            customerId: $request->filled('customer_id') ? $request->integer('customer_id') : null,
            expenseTypeId: $request->filled('expense_type_id') ? $request->integer('expense_type_id') : null,
        );
    }

    /** The first day of the range, as the user typed it (company timezone). */
    public function fromDate(): string
    {
        return LocalTime::convert($this->from)->toDateString();
    }

    /** The last day of the range, as the user typed it (company timezone). */
    public function toDate(): string
    {
        return LocalTime::convert($this->to)->toDateString();
    }

    public function toQueryString(): array
    {
        return array_filter([
            'from' => $this->fromDate(),
            'to' => $this->toDate(),
            'branch_id' => $this->branchId,
            'agent_id' => $this->agentId,
            'status' => $this->status,
            'account_type' => $this->accountType,
            'group_by' => $this->groupBy,
            'customer_id' => $this->customerId,
            'expense_type_id' => $this->expenseTypeId,
        ], fn ($v) => $v !== null);
    }
}
