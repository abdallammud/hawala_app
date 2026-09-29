<?php

namespace App\Services\Transfers;

use App\Enums\JournalType;
use App\Enums\LedgerAccountType;
use App\Enums\TransferStatus;
use App\Models\Branch;
use App\Models\LedgerAccount;
use App\Models\Transfer;
use App\Models\User;
use App\Notifications\LargeTransferCreated;
use App\Services\CommissionCalculator;
use App\Services\Ledger\LedgerService;
use App\Services\Rates\QuoteService;
use App\Support\Settings;
use Illuminate\Support\Facades\DB;

class TransferService
{
    public function __construct(
        private LedgerService $ledger,
        private QuoteService $quotes,
        private CommissionCalculator $commissions,
    ) {}

    /**
     * Create a transfer and post `transfer_sent`. The quote is recalculated
     * here server-side — client numbers are never trusted (BUILD.md §8).
     *
     * @param  array{sender_name:string,sender_phone:string,receiver_name:string,receiver_phone:string,
     *               receiver_account_type_id:?int,receiver_account_number:?string,
     *               currency:string,amount_cents:int,is_uncollected:bool,note:?string}  $data
     */
    public function create(User $agent, Branch $branch, array $data, string $idempotencyKey): Transfer
    {
        // Idempotency (money rule 10): a repeated token returns the existing
        // transfer rather than posting twice.
        $existing = Transfer::query()->where('idempotency_key', $idempotencyKey)->first();

        if ($existing) {
            return $existing;
        }

        $marginBp = (int) Settings::get('currency.margin_bp', 0);
        $quote = $this->quotes->quote($data['currency'], $data['amount_cents'], $marginBp);

        $sendingShare = $this->commissions->sendingShare($agent, $quote['fee_cents']);

        $isLarge = $quote['amount_cents'] >= Transfer::largeThresholdCents();
        $holdLarge = (bool) Settings::get('fees.hold_large_transfers', false);
        $status = ($isLarge && $holdLarge) ? TransferStatus::AwaitingApproval : TransferStatus::Queued;

        $transfer = DB::transaction(function () use ($agent, $branch, $data, $idempotencyKey, $quote, $sendingShare, $isLarge, $status, $marginBp) {
            $transfer = Transfer::create([
                'code' => Transfer::generateCode(),
                'idempotency_key' => $idempotencyKey,
                'sending_agent_id' => $agent->id,
                'branch_id' => $branch->id,
                'sender_name' => $data['sender_name'],
                'sender_phone' => $data['sender_phone'],
                'receiver_name' => $data['receiver_name'],
                'receiver_phone' => $data['receiver_phone'],
                'receiver_account_type_id' => $data['receiver_account_type_id'] ?? null,
                'receiver_account_number' => $data['receiver_account_number'] ?? null,
                'original_currency' => $data['currency'],
                'original_amount_cents' => $quote['original_amount_cents'],
                'market_rate' => $quote['market_rate'],
                'margin_bp' => $marginBp,
                'customer_rate' => $quote['customer_rate'],
                'amount_cents' => $quote['amount_cents'],
                'fee_cents' => $quote['fee_cents'],
                'fx_income_cents' => $quote['fx_income_cents'],
                'total_debit_cents' => $quote['total_debit_cents'],
                'collect_original_cents' => $quote['collect_original_cents'],
                'sending_share_cents' => $sendingShare['share_cents'],
                'fee_snapshot' => $quote['fee_snapshot'],
                'commission_snapshot' => ['sending' => $sendingShare['snapshot']],
                'status' => $status,
                'is_large' => $isLarge,
                'is_uncollected' => $data['is_uncollected'] ?? false,
                'note' => $data['note'] ?? null,
            ]);

            $this->ledger->post(
                JournalType::TransferSent,
                $this->sentLines($agent, $transfer),
                $transfer,
                $agent
            );

            return $transfer;
        });

        if ($isLarge) {
            User::permission('transfers.approve_large')
                ->where('id', '!=', $agent->id)
                ->each(fn (User $admin) => $admin->notify(new LargeTransferCreated($transfer)));
        }

        return $transfer;
    }

    /**
     * The `transfer_sent` lines from BUILD.md §4. Zero-amount lines are
     * omitted — LedgerService rejects them outright.
     *
     * @return array<int, array{0: LedgerAccount, 1: int}>
     */
    private function sentLines(User $agent, Transfer $transfer): array
    {
        $lines = [
            [$this->ledger->accountFor($agent, LedgerAccountType::AgentSend), $transfer->total_debit_cents],
            [$this->ledger->system(LedgerAccountType::TransfersPayable), -$transfer->amount_cents],
        ];

        if ($transfer->fee_cents > 0) {
            $lines[] = [$this->ledger->system(LedgerAccountType::FeeIncome), -$transfer->fee_cents];
        }

        if ($transfer->fx_income_cents > 0) {
            $lines[] = [$this->ledger->system(LedgerAccountType::FxIncome), -$transfer->fx_income_cents];
        }

        if ($transfer->sending_share_cents > 0) {
            $lines[] = [$this->ledger->system(LedgerAccountType::CommissionExpense), $transfer->sending_share_cents];
            $lines[] = [$this->ledger->accountFor($agent, LedgerAccountType::AgentCommission), -$transfer->sending_share_cents];
        }

        return $lines;
    }

    /**
     * Recent near-identical transfer by the same agent — a warning, never a
     * block (BUILD.md §8). The agent confirms with "Send anyway".
     */
    public function findRecentDuplicate(User $agent, string $receiverPhone, int $amountCents): ?Transfer
    {
        $minutes = (int) Settings::get('general.duplicate_warning_minutes', 10);

        if ($minutes <= 0) {
            return null;
        }

        return Transfer::query()
            ->where('sending_agent_id', $agent->id)
            ->where('receiver_phone', $receiverPhone)
            ->where('amount_cents', $amountCents)
            ->whereIn('status', [TransferStatus::Queued, TransferStatus::Claimed, TransferStatus::Paid, TransferStatus::AwaitingApproval])
            ->where('created_at', '>=', now()->subMinutes($minutes))
            ->latest()
            ->first();
    }
}
