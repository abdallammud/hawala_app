<?php

namespace App\Reports;

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

/**
 * The filter inputs a report can read. Every report gets a date range;
 * the rest are optional and each report declares which it uses.
 */
class ReportFilters
{
    public function __construct(
        public readonly CarbonImmutable $from,
        public readonly CarbonImmutable $to,
        public readonly ?int $branchId = null,
        public readonly ?int $agentId = null,
        public readonly ?string $status = null,
        public readonly ?string $accountType = null,
        public readonly ?string $groupBy = null,
    ) {}

    public static function fromRequest(Request $request): self
    {
        // Default to the current month — the range a manager asks for most.
        $from = $request->filled('from')
            ? CarbonImmutable::parse($request->string('from')->toString())->startOfDay()
            : CarbonImmutable::now()->startOfMonth();

        $to = $request->filled('to')
            ? CarbonImmutable::parse($request->string('to')->toString())->endOfDay()
            : CarbonImmutable::now()->endOfDay();

        return new self(
            from: $from,
            to: $to,
            branchId: $request->filled('branch_id') ? $request->integer('branch_id') : null,
            agentId: $request->filled('agent_id') ? $request->integer('agent_id') : null,
            status: $request->filled('status') ? $request->string('status')->toString() : null,
            accountType: $request->filled('account_type') ? $request->string('account_type')->toString() : null,
            groupBy: $request->filled('group_by') ? $request->string('group_by')->toString() : null,
        );
    }

    public function toQueryString(): array
    {
        return array_filter([
            'from' => $this->from->toDateString(),
            'to' => $this->to->toDateString(),
            'branch_id' => $this->branchId,
            'agent_id' => $this->agentId,
            'status' => $this->status,
            'account_type' => $this->accountType,
            'group_by' => $this->groupBy,
        ], fn ($v) => $v !== null);
    }
}
