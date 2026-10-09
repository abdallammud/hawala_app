<?php

namespace App\Reports;

use App\Models\User;
use Illuminate\Support\Collection;

class ReportRegistry
{
    /** @var class-string<Report>[] */
    private const REPORTS = [
        TransfersReport::class,
        AgentStatementReport::class,
        UncollectedReport::class,
        CreditOwedReport::class,
        CommissionsReport::class,
        PayoutsByMethodReport::class,
        BranchSummaryReport::class,
        ProfitReport::class,
        LargeTransfersReport::class,
        CancellationsReport::class,
        CompanyAccountsReport::class,
        CustomersReport::class,
        CustomerStatementReport::class,
    ];

    /** @return Collection<int, Report> */
    public function all(): Collection
    {
        return collect(self::REPORTS)->map(fn (string $class) => app($class));
    }

    /** @return Collection<int, Report> reports this user may run */
    public function visibleTo(User $user): Collection
    {
        return $this->all()->filter(fn (Report $r) => $user->can($r->permission()))->values();
    }

    public function find(string $key): ?Report
    {
        return $this->all()->first(fn (Report $r) => $r->key() === $key);
    }
}
