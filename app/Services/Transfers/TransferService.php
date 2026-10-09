<?php

namespace App\Services\Transfers;

use App\Enums\JournalType;
use App\Enums\LedgerAccountType;
use App\Enums\QuoteMode;
use App\Enums\SmsEvent;
use App\Enums\TransferStatus;
use App\Jobs\SendTemplatedSms;
use App\Models\Branch;
use App\Models\LedgerAccount;
use App\Models\Transfer;
use App\Models\User;
use App\Notifications\LargeTransferCreated;
use App\Services\CommissionCalculator;
use App\Services\Customers\CustomerService;
use App\Services\Ledger\LedgerService;
use App\Services\Rates\QuoteService;
use App\Support\Settings;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class TransferService
{
    public function __construct(
        private LedgerService $ledger,
        private QuoteService $quotes,
        private CommissionCalculator $commissions,
        private CustomerService $customers,
    ) {}

    /**
     * The tracking number the New Transfer form shows before the transfer
     * exists. Held against the form's idempotency token so the number on
     * screen is the number the transfer gets, and a page reload keeps it.
     */
    public function reserveTrackingCode(string $idempotencyKey): string
    {
        return Cache::remember(
            $this->trackingCacheKey($idempotencyKey),
            now()->addDay(),
            fn () => Transfer::generateCode(),
        );
    }

    private function trackingCacheKey(string $idempotencyKey): string
    {
        return 'transfer.tracking.'.$idempotencyKey;
    }

    /**
     * Create a transfer and post `transfer_sent`. The quote is recalculated
     * here server-side — client numbers are never trusted (BUILD.md §8).
     *
     * `amount_cents` is read through `mode`: in Pay mode it is what the
     * customer hands over in `currency`, in Receive mode it is what the
     * receiver is paid, in `payout_currency` (USD unless the agent changed it).
     *
     * @param  array{sender_name:string,sender_phone:string,sender_country?:?string,sender_city?:?string,sender_province?:?string,
     *               receiver_name:string,receiver_phone:string,receiver_country?:?string,receiver_city?:?string,receiver_province?:?string,
     *               receiver_account_type_id:?int,receiver_account_number:?string,
     *               currency:string,amount_cents:int,mode?:QuoteMode,payout_currency?:string,
     *               is_uncollected:bool,note:?string}  $data
     */
    public function create(User $agent, Branch $branch, array $data, string $idempotencyKey): Transfer
    {
        // Idempotency (money rule 10): a repeated token returns the existing
        // transfer rather than posting twice.
        $existing = Transfer::query()->where('idempotency_key', $idempotencyKey)->first();

        if ($existing) {
            return $existing;
        }

        // Receive is the safe default for programmatic callers: the amount
        // given is the amount the receiver gets, exactly as before two-way
        // quoting existed. The form always says which mode it means.
        $mode = $data['mode'] ?? QuoteMode::Receive;
        $marginBp = (int) Settings::get('currency.margin_bp', 0);

        $quote = $this->quotes->quote(
            $mode,
            $data['currency'],
            $data['amount_cents'],
            $marginBp,
            $data['payout_currency'] ?? 'USD',
        );

        $sendingShare = $this->commissions->sendingShare($agent, $quote['fee_cents']);

        $code = Cache::pull($this->trackingCacheKey($idempotencyKey));

        if (! $code || Transfer::query()->where('code', $code)->exists()) {
            $code = Transfer::generateCode();
        }

        $isLarge = $quote['amount_cents'] >= Transfer::largeThresholdCents();
        $holdLarge = (bool) Settings::get('fees.hold_large_transfers', false);
        $status = ($isLarge && $holdLarge) ? TransferStatus::AwaitingApproval : TransferStatus::Queued;

        $transfer = DB::transaction(function () use ($agent, $branch, $code, $data, $idempotencyKey, $quote, $sendingShare, $isLarge, $status, $marginBp) {
            $customer = $this->customers->resolve($data, $agent);

            $transfer = Transfer::create([
                'code' => $code,
                'idempotency_key' => $idempotencyKey,
                'sending_agent_id' => $agent->id,
                'customer_id' => $customer->id,
                'branch_id' => $branch->id,
                'origin_branch_id' => $agent->branch_id,
                'sender_name' => $data['sender_name'],
                'sender_phone' => $data['sender_phone'],
                'sender_country' => $data['sender_country'] ?? null,
                'sender_city' => $data['sender_city'] ?? null,
                'sender_province' => $data['sender_province'] ?? null,
                'receiver_name' => $data['receiver_name'],
                'receiver_phone' => $data['receiver_phone'],
                'receiver_country' => $data['receiver_country'] ?? null,
                'receiver_city' => $data['receiver_city'] ?? null,
                'receiver_province' => $data['receiver_province'] ?? null,
                'receiver_account_type_id' => $data['receiver_account_type_id'] ?? null,
                'receiver_account_number' => $data['receiver_account_number'] ?? null,
                'original_currency' => $data['currency'],
                'quote_mode' => $quote['quote_mode'],
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

            SendTemplatedSms::dispatch(SmsEvent::LargeTransfer, $transfer->id);
        }

        // Dispatched after the transaction commits, so the job never reads a
        // transfer that is still uncommitted or about to be rolled back.
        // Only once it is really on its way: a transfer held for approval
        // must not tell the receiver their money has been sent.
        if ($transfer->status === TransferStatus::Queued) {
            SendTemplatedSms::dispatch(SmsEvent::TransferSent, $transfer->id);
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
     *
     * Compared on whichever leg the agent typed: the payout in Receive mode
     * (normalised to USD, since that is what is stored), the cash collected
     * in Pay mode. The couple of cents' tolerance covers a payout that was
     * solved down to fit the fee exactly.
     */
    public function findRecentDuplicate(
        User $agent,
        string $receiverPhone,
        int $amountCents,
        QuoteMode $mode = QuoteMode::Receive,
        string $currency = 'USD',
        string $payoutCurrency = 'USD',
    ): ?Transfer {
        $minutes = (int) Settings::get('general.duplicate_warning_minutes', 10);

        if ($minutes <= 0) {
            return null;
        }

        if ($mode === QuoteMode::Receive) {
            $amountCents = $this->quotes->toUsd($payoutCurrency, $amountCents);
        }

        return Transfer::query()
            ->where('sending_agent_id', $agent->id)
            ->where('receiver_phone', $receiverPhone)
            ->when(
                $mode === QuoteMode::Receive,
                fn ($q) => $q->where('amount_cents', $amountCents),
                fn ($q) => $q->where('original_currency', $currency)
                    ->whereBetween('collect_original_cents', [$amountCents - 2, $amountCents]),
            )
            ->whereIn('status', [TransferStatus::Queued, TransferStatus::Claimed, TransferStatus::Paid, TransferStatus::AwaitingApproval])
            ->where('created_at', '>=', now()->subMinutes($minutes))
            ->latest()
            ->first();
    }
}
