<?php

namespace App\Services\Transfers;

use App\Enums\CancellationRequestStatus;
use App\Enums\JournalType;
use App\Enums\LedgerAccountType;
use App\Enums\SmsEvent;
use App\Enums\TransferStatus;
use App\Exceptions\Transfers\TransferStateException;
use App\Jobs\SendTemplatedSms;
use App\Models\CancellationRequest;
use App\Models\Transfer;
use App\Models\User;
use App\Notifications\CancellationRequested;
use App\Services\Email\EmailNotifier;
use App\Services\Ledger\LedgerService;
use App\Support\Settings;
use Illuminate\Support\Facades\DB;

class TransferCancellationService
{
    public function __construct(private LedgerService $ledger) {}

    /**
     * Cancel a transfer and reverse `transfer_sent`.
     *
     * With fees.refund_on_cancel on, every line is negated exactly. With it
     * off, the company keeps the fee and any FX income — so those lines are
     * omitted and agent_send only gets `amount` back — but the sending
     * commission is *always* reversed (BUILD.md §4), otherwise an agent
     * could earn commission on cancelled transfers.
     */
    public function cancel(Transfer $transfer, User $by, string $reason, JournalType $type = JournalType::TransferCancelled): Transfer
    {
        $cancelled = DB::transaction(function () use ($transfer, $by, $reason, $type) {
            $fresh = Transfer::query()->whereKey($transfer->id)->lockForUpdate()->firstOrFail();

            if (! $fresh->status->isOpen()) {
                throw TransferStateException::notCancellable();
            }

            // Rejection of a held large transfer always refunds in full.
            $refundFee = $type === JournalType::TransferRejected
                || (bool) Settings::get('fees.refund_on_cancel', true);

            $agent = $fresh->sendingAgent;

            $refundToAgent = $refundFee ? $fresh->total_debit_cents : $fresh->amount_cents;

            $lines = [
                [$this->ledger->accountFor($agent, LedgerAccountType::AgentSend), -$refundToAgent],
                [$this->ledger->system(LedgerAccountType::TransfersPayable), $fresh->amount_cents],
            ];

            if ($refundFee) {
                if ($fresh->fee_cents > 0) {
                    $lines[] = [$this->ledger->system(LedgerAccountType::FeeIncome), $fresh->fee_cents];
                }

                if ($fresh->fx_income_cents > 0) {
                    $lines[] = [$this->ledger->system(LedgerAccountType::FxIncome), $fresh->fx_income_cents];
                }
            }

            // Always claw the sending commission back.
            if ($fresh->sending_share_cents > 0) {
                $lines[] = [$this->ledger->system(LedgerAccountType::CommissionExpense), -$fresh->sending_share_cents];
                $lines[] = [$this->ledger->accountFor($agent, LedgerAccountType::AgentCommission), $fresh->sending_share_cents];
            }

            $this->ledger->post($type, $lines, $fresh, $by, null, $reason);

            $fresh->update([
                'status' => $type === JournalType::TransferRejected ? TransferStatus::Rejected : TransferStatus::Cancelled,
                'cancelled_by' => $by->id,
                'cancelled_at' => now(),
                'cancel_reason' => $reason,
                'fee_refunded' => $refundFee,
                'claim_expires_at' => null,
            ]);

            return $fresh;
        });

        SendTemplatedSms::dispatch(SmsEvent::TransferCancelled, $cancelled->id);
        app(EmailNotifier::class)->transferCancelled($cancelled);

        return $cancelled;
    }

    /** A sending agent asks an admin to cancel a transfer already claimed by a payout agent. */
    public function requestCancellation(Transfer $transfer, User $agent, string $reason): CancellationRequest
    {
        if ($transfer->status !== TransferStatus::Claimed) {
            throw TransferStateException::notCancellable();
        }

        $request = CancellationRequest::create([
            'transfer_id' => $transfer->id,
            'requested_by' => $agent->id,
            'reason' => $reason,
            'status' => CancellationRequestStatus::Pending,
        ]);

        User::permission('transfers.approve_cancel')
            ->where('id', '!=', $agent->id)
            ->each(fn (User $admin) => $admin->notify(new CancellationRequested($request)));

        return $request;
    }

    public function approveCancellation(CancellationRequest $request, User $admin): CancellationRequest
    {
        $this->guardPending($request);

        // Maker ≠ checker, whoever else the approver also is.
        if ($request->requested_by === $admin->id) {
            throw TransferStateException::selfApproval();
        }

        DB::transaction(function () use ($request, $admin) {
            $this->cancel($request->transfer, $admin, $request->reason);

            $request->update([
                'status' => CancellationRequestStatus::Approved,
                'decided_by' => $admin->id,
                'decided_at' => now(),
            ]);
        });

        return $request;
    }

    public function rejectCancellation(CancellationRequest $request, User $admin): CancellationRequest
    {
        $this->guardPending($request);

        $request->update([
            'status' => CancellationRequestStatus::Rejected,
            'decided_by' => $admin->id,
            'decided_at' => now(),
        ]);

        return $request;
    }

    /** Approve a held large transfer, releasing it into the queue. */
    public function approveLarge(Transfer $transfer, User $admin): Transfer
    {
        if ($transfer->status !== TransferStatus::AwaitingApproval) {
            throw new TransferStateException(__('This transfer is not awaiting approval.'));
        }

        if ($transfer->sending_agent_id === $admin->id) {
            throw TransferStateException::selfApproval();
        }

        $transfer->update(['status' => TransferStatus::Queued]);

        // Held transfers skip the transfer_sent SMS at creation; it fires
        // here instead, once the money is genuinely on its way.
        SendTemplatedSms::dispatch(SmsEvent::TransferSent, $transfer->id);
        SendTemplatedSms::dispatch(SmsEvent::ReceiverWaiting, $transfer->id);
        app(EmailNotifier::class)->transferSent($transfer);

        return $transfer;
    }

    /** Reject a held large transfer — full reversal regardless of the refund setting. */
    public function rejectLarge(Transfer $transfer, User $admin, string $reason): Transfer
    {
        if ($transfer->status !== TransferStatus::AwaitingApproval) {
            throw new TransferStateException(__('This transfer is not awaiting approval.'));
        }

        return $this->cancel($transfer, $admin, $reason, JournalType::TransferRejected);
    }

    private function guardPending(CancellationRequest $request): void
    {
        if ($request->status !== CancellationRequestStatus::Pending) {
            throw TransferStateException::alreadyDecided();
        }
    }
}
