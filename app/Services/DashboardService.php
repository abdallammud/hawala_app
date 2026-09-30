<?php

namespace App\Services;

use App\Enums\BalanceRequestStatus;
use App\Enums\CancellationRequestStatus;
use App\Enums\LedgerAccountType;
use App\Enums\TransferStatus;
use App\Models\BalanceRequest;
use App\Models\CancellationRequest;
use App\Models\ClaimExpiry;
use App\Models\CompanyAccount;
use App\Models\ExchangeRate;
use App\Models\LedgerAccount;
use App\Models\Transfer;
use App\Models\User;

/**
 * The per-role dashboard figures from BUILD.md §11.
 */
class DashboardService
{
    public function forSendingAgent(User $agent): array
    {
        $today = Transfer::query()
            ->where('sending_agent_id', $agent->id)
            ->whereDate('created_at', today())
            ->get();

        return [
            'send_balance' => $this->balance($agent, LedgerAccountType::AgentSend),
            'credit_owed' => $this->balance($agent, LedgerAccountType::AgentCredit),
            'commission' => $this->balance($agent, LedgerAccountType::AgentCommission),
            'uncollected' => (int) Transfer::query()
                ->where('sending_agent_id', $agent->id)
                ->where('is_uncollected', true)
                ->whereNull('fully_collected_at')
                ->get()
                ->sum(fn (Transfer $t) => $t->outstandingCents()),
            'today_count' => $today->count(),
            'today_amount' => (int) $today->sum('amount_cents'),
            'recent' => Transfer::query()
                ->where('sending_agent_id', $agent->id)
                ->with('branch')
                ->latest()
                ->limit(8)
                ->get(),
        ];
    }

    public function forPayoutAgent(User $agent): array
    {
        return [
            'payout_balance' => $this->balance($agent, LedgerAccountType::AgentPayout),
            'commission' => $this->balance($agent, LedgerAccountType::AgentCommission),
            'queue_count' => Transfer::query()
                ->where('status', TransferStatus::Queued)
                ->where('branch_id', $agent->branch_id)
                ->count(),
            'queue_amount' => (int) Transfer::query()
                ->where('status', TransferStatus::Queued)
                ->where('branch_id', $agent->branch_id)
                ->sum('amount_cents'),
            'claimed' => Transfer::query()
                ->where('status', TransferStatus::Claimed)
                ->where('claimed_by', $agent->id)
                ->orderBy('claim_expires_at')
                ->get(),
            'paid_today' => Transfer::query()
                ->where('paid_by', $agent->id)
                ->whereDate('paid_at', today())
                ->count(),
        ];
    }

    public function forAdmin(): array
    {
        $todayTransfers = Transfer::query()->whereDate('created_at', today())->get();

        // "Money available in Somalia" = Somali company accounts plus every
        // payout agent's float (BUILD.md §11).
        $somaliCompany = (int) CompanyAccount::query()
            ->where('location', 'somalia')
            ->with('ledgerAccount')
            ->get()
            ->sum(fn (CompanyAccount $a) => $a->ledgerAccount?->balance_cents ?? 0);

        $payoutFloat = (int) LedgerAccount::query()
            ->where('type', LedgerAccountType::AgentPayout->value)
            ->sum('balance_cents');

        $latestRate = ExchangeRate::query()->latest('fetched_at')->first();

        return [
            'somalia_available' => $somaliCompany + $payoutFloat,
            'somalia_company' => $somaliCompany,
            'payout_float' => $payoutFloat,
            'waiting_to_pay' => $this->systemBalance(LedgerAccountType::TransfersPayable),
            'today_count' => $todayTransfers->count(),
            'today_volume' => (int) $todayTransfers->sum('amount_cents'),
            'today_fees' => (int) $todayTransfers->sum('fee_cents'),
            'today_profit' => (int) $todayTransfers->sum(
                fn (Transfer $t) => $t->fee_cents + $t->fx_income_cents
                    - $t->sending_share_cents - ($t->payout_share_cents ?? 0)
            ),
            'pending_balance_requests' => BalanceRequest::query()->where('status', BalanceRequestStatus::Pending)->count(),
            'pending_cancellations' => CancellationRequest::query()->where('status', CancellationRequestStatus::Pending)->count(),
            'awaiting_approval' => Transfer::query()->where('status', TransferStatus::AwaitingApproval)->count(),
            'expired_today' => ClaimExpiry::query()->whereDate('expired_at', today())->count(),
            'large_this_month' => Transfer::query()
                ->where('is_large', true)
                ->where('created_at', '>=', now()->startOfMonth())
                ->count(),
            'uncollected_total' => (int) Transfer::query()
                ->where('is_uncollected', true)
                ->whereNull('fully_collected_at')
                ->get()
                ->sum(fn (Transfer $t) => $t->outstandingCents()),
            'agents_negative_credit' => LedgerAccount::query()
                ->where('type', LedgerAccountType::AgentCredit->value)
                ->where('balance_cents', '>', 0)
                ->count(),
            'rate_fetched_at' => $latestRate?->fetched_at,
            'recent' => Transfer::query()->with(['sendingAgent', 'branch'])->latest()->limit(8)->get(),
        ];
    }

    private function balance(User $user, LedgerAccountType $type): int
    {
        // Read-only: don't create an account just because someone looked.
        return (int) (LedgerAccount::query()
            ->where('type', $type->value)
            ->where('owner_type', $user->getMorphClass())
            ->where('owner_id', $user->id)
            ->value('balance_cents') ?? 0);
    }

    /** Also read-only — viewing a dashboard must not insert a system account. */
    private function systemBalance(LedgerAccountType $type): int
    {
        return (int) (LedgerAccount::query()
            ->where('type', $type->value)
            ->whereNull('owner_id')
            ->value('balance_cents') ?? 0);
    }
}
