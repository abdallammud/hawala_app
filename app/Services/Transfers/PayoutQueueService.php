<?php

namespace App\Services\Transfers;

use App\Enums\JournalType;
use App\Enums\LedgerAccountType;
use App\Enums\SmsEvent;
use App\Enums\TransferStatus;
use App\Exceptions\Ledger\InsufficientBalanceException;
use App\Exceptions\Transfers\TransferStateException;
use App\Jobs\SendTemplatedSms;
use App\Models\ClaimExpiry;
use App\Models\PayoutAccount;
use App\Models\Transfer;
use App\Models\User;
use App\Notifications\ClaimExpired;
use App\Notifications\TransferAssigned;
use App\Services\CommissionCalculator;
use App\Services\Ledger\LedgerService;
use App\Support\Money;
use App\Support\Settings;
use Illuminate\Support\Facades\DB;

class PayoutQueueService
{
    public function __construct(
        private LedgerService $ledger,
        private CommissionCalculator $commissions,
    ) {}

    /**
     * Claim a queued transfer. Locks the row so two agents racing for the
     * same transfer can't both win.
     */
    public function claim(Transfer $transfer, User $agent): Transfer
    {
        return DB::transaction(function () use ($transfer, $agent) {
            $fresh = Transfer::query()->whereKey($transfer->id)->lockForUpdate()->firstOrFail();

            if ($fresh->status !== TransferStatus::Queued) {
                throw TransferStateException::notClaimable();
            }

            $this->guardSelfClaim($fresh, $agent);

            if (! Settings::get('payout.allow_negative', false)) {
                $payout = $this->ledger->accountFor($agent, LedgerAccountType::AgentPayout);

                if ($payout->balance_cents < $fresh->amount_cents) {
                    throw new InsufficientBalanceException(__(
                        'Your payout balance (:balance) is less than this transfer (:amount). Request funding first.',
                        ['balance' => Money::format($payout->balance_cents), 'amount' => Money::format($fresh->amount_cents)]
                    ));
                }
            }

            $fresh->update([
                'status' => TransferStatus::Claimed,
                'claimed_by' => $agent->id,
                'claimed_at' => now(),
                'claim_expires_at' => now()->addMinutes((int) Settings::get('payout.claim_minutes', 30)),
                'assigned_by' => null,
            ]);

            return $fresh;
        });
    }

    /** Release a claim back to the queue — used by the claimer themself. */
    public function release(Transfer $transfer, User $agent): Transfer
    {
        return DB::transaction(function () use ($transfer, $agent) {
            $fresh = Transfer::query()->whereKey($transfer->id)->lockForUpdate()->firstOrFail();

            if ($fresh->status !== TransferStatus::Claimed) {
                throw TransferStateException::notClaimable();
            }

            if ($fresh->claimed_by !== $agent->id && ! $agent->can('queue.unassign')) {
                throw TransferStateException::notYours();
            }

            $this->returnToQueue($fresh);

            return $fresh;
        });
    }

    /**
     * Mark a claimed transfer paid: posts `transfer_paid`, computes the
     * payout and company commission shares, and freezes them on the record.
     */
    public function markPaid(Transfer $transfer, User $payer, PayoutAccount $payoutAccount, ?string $reference = null): Transfer
    {
        if (Settings::get('payout.reference_required', false) && blank($reference)) {
            throw new TransferStateException(__('A payment reference is required.'));
        }

        $paid = DB::transaction(function () use ($transfer, $payer, $payoutAccount, $reference) {
            $fresh = Transfer::query()->whereKey($transfer->id)->lockForUpdate()->firstOrFail();

            if ($fresh->status !== TransferStatus::Claimed) {
                throw TransferStateException::notPayable();
            }

            // An admin with queue.mark_paid may act for the claimer, but
            // commission always follows whoever actually holds the claim.
            if ($fresh->claimed_by !== $payer->id && ! $payer->can('queue.force_assign')) {
                throw TransferStateException::notYours();
            }

            $claimer = User::findOrFail($fresh->claimed_by);

            // Re-checked here, not only at claim: the setting may have been
            // switched off since, or an admin may have assigned it.
            $this->guardSelfClaim($fresh, $claimer);

            if ($payoutAccount->user_id !== $claimer->id) {
                throw new TransferStateException(__('That payout account does not belong to the paying agent.'));
            }

            $payoutShare = $this->commissions->payoutShare($claimer, $fresh->fee_cents, $fresh->sending_share_cents);
            $companyShare = $this->commissions->companyShare($fresh->fee_cents, $fresh->sending_share_cents, $payoutShare['share_cents']);

            $lines = [
                [$this->ledger->system(LedgerAccountType::TransfersPayable), $fresh->amount_cents],
                [$this->ledger->accountFor($claimer, LedgerAccountType::AgentPayout), -$fresh->amount_cents],
            ];

            if ($payoutShare['share_cents'] > 0) {
                $lines[] = [$this->ledger->system(LedgerAccountType::CommissionExpense), $payoutShare['share_cents']];
                $lines[] = [$this->ledger->accountFor($claimer, LedgerAccountType::AgentCommission), -$payoutShare['share_cents']];
            }

            $this->ledger->post(JournalType::TransferPaid, $lines, $fresh, $payer);

            $snapshot = $fresh->commission_snapshot;
            $snapshot['payout'] = $payoutShare['snapshot'];

            $fresh->update([
                'status' => TransferStatus::Paid,
                'paid_by' => $payer->id,
                'payout_account_id' => $payoutAccount->id,
                'payout_reference' => $reference,
                'paid_at' => now(),
                'payout_share_cents' => $payoutShare['share_cents'],
                'company_share_cents' => $companyShare,
                'commission_snapshot' => $snapshot,
                'claim_expires_at' => null,
            ]);

            return $fresh;
        });

        SendTemplatedSms::dispatch(SmsEvent::TransferPaid, $paid->id);

        return $paid;
    }

    /** Admin assigns a queued transfer directly to a payout agent. */
    public function forceAssign(Transfer $transfer, User $payoutAgent, User $admin): Transfer
    {
        $assigned = DB::transaction(function () use ($transfer, $payoutAgent, $admin) {
            $fresh = Transfer::query()->whereKey($transfer->id)->lockForUpdate()->firstOrFail();

            if (! in_array($fresh->status, [TransferStatus::Queued, TransferStatus::Claimed], true)) {
                throw TransferStateException::notClaimable();
            }

            $this->guardSelfClaim($fresh, $payoutAgent);

            $fresh->update([
                'status' => TransferStatus::Claimed,
                'claimed_by' => $payoutAgent->id,
                'claimed_at' => now(),
                'claim_expires_at' => now()->addMinutes((int) Settings::get('payout.claim_minutes', 30)),
                'assigned_by' => $admin->id,
            ]);

            return $fresh;
        });

        $payoutAgent->notify(new TransferAssigned($assigned));

        return $assigned;
    }

    /**
     * Expire claims past their deadline. Called by the scheduler every
     * minute and lazily when the queue page loads.
     *
     * @return int number of claims expired
     */
    public function expireDueClaims(): int
    {
        $due = Transfer::query()
            ->where('status', TransferStatus::Claimed)
            ->whereNotNull('claim_expires_at')
            ->where('claim_expires_at', '<=', now())
            ->get();

        $expired = 0;

        foreach ($due as $transfer) {
            $agentName = $transfer->claimedBy?->name ?? __('someone');

            $changed = DB::transaction(function () use ($transfer) {
                $fresh = Transfer::query()->whereKey($transfer->id)->lockForUpdate()->firstOrFail();

                // Re-check under the lock: it may have been paid in the meantime.
                if ($fresh->status !== TransferStatus::Claimed || $fresh->claim_expires_at?->isFuture()) {
                    return false;
                }

                // Recorded before the claim is cleared, since returnToQueue
                // wipes claimed_by — the §12 time-outs report needs it.
                ClaimExpiry::create([
                    'transfer_id' => $fresh->id,
                    'user_id' => $fresh->claimed_by,
                    'expired_at' => now(),
                ]);

                $this->returnToQueue($fresh);

                return true;
            });

            if ($changed) {
                $expired++;

                User::permission('queue.force_assign')
                    ->each(fn (User $admin) => $admin->notify(new ClaimExpired($transfer, $agentName)));

                // The transfer row has already lost claimed_by, so the agent's
                // number is passed explicitly.
                SendTemplatedSms::dispatch(
                    SmsEvent::ClaimExpired,
                    $transfer->id,
                    overrideRecipients: array_filter([$transfer->claimedBy?->phone]),
                );
            }
        }

        return $expired;
    }

    /** Whoever sent a transfer may not also pay it, unless the company allows it. */
    private function guardSelfClaim(Transfer $transfer, User $agent): void
    {
        if ($transfer->sending_agent_id === $agent->id && ! Settings::get('payout.allow_self_claim', false)) {
            throw TransferStateException::selfClaim();
        }
    }

    private function returnToQueue(Transfer $transfer): void
    {
        $transfer->update([
            'status' => TransferStatus::Queued,
            'claimed_by' => null,
            'claimed_at' => null,
            'claim_expires_at' => null,
            'assigned_by' => null,
        ]);
    }
}
