<?php

namespace App\Services\Balances;

use App\Enums\BalanceRequestKind;
use App\Enums\BalanceRequestStatus;
use App\Enums\JournalType;
use App\Enums\LedgerAccountType;
use App\Enums\SmsEvent;
use App\Exceptions\Balances\MaxCreditExceededException;
use App\Exceptions\Balances\SelfApprovalException;
use App\Jobs\SendTemplatedSms;
use App\Models\BalanceRequest;
use App\Models\CompanyAccount;
use App\Models\JournalEntry;
use App\Models\User;
use App\Notifications\BalanceRequestDecided;
use App\Notifications\BalanceRequestSubmitted;
use App\Services\Email\EmailNotifier;
use App\Services\Ledger\LedgerService;
use App\Support\Money;
use App\Support\Settings;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class BalanceRequestService
{
    public function __construct(private LedgerService $ledger) {}

    /** Create a pending balance request and notify eligible approvers. */
    public function submit(
        BalanceRequestKind $kind,
        User $beneficiary,
        int $amountCents,
        User $requestedBy,
        ?CompanyAccount $companyAccount = null,
        ?string $note = null,
    ): BalanceRequest {
        $request = $this->createRow($kind, $beneficiary, $amountCents, $requestedBy, $companyAccount, $note);

        User::permission($kind->approvePermission())
            ->where('id', '!=', $requestedBy->id)
            ->each(fn (User $approver) => $approver->notify(new BalanceRequestSubmitted($request)));

        return $request;
    }

    private function createRow(
        BalanceRequestKind $kind,
        User $beneficiary,
        int $amountCents,
        User $requestedBy,
        ?CompanyAccount $companyAccount,
        ?string $note,
    ): BalanceRequest {
        return BalanceRequest::create([
            'user_id' => $beneficiary->id,
            'kind' => $kind,
            'amount_cents' => $amountCents,
            'original_currency' => 'USD',
            'original_amount_cents' => $amountCents,
            'company_account_id' => $companyAccount?->id,
            'status' => BalanceRequestStatus::Pending,
            'requested_by' => $requestedBy->id,
            'note' => $note,
        ]);
    }

    /** Approve a pending request. Maker ≠ checker: the approver can't be who requested it. */
    public function approve(BalanceRequest $request, User $approver, ?string $note = null): BalanceRequest
    {
        $this->guardPending($request);

        if ($approver->id === $request->requested_by) {
            throw new SelfApprovalException;
        }

        return $this->finalizeApproval($request, $approver, $note);
    }

    public function reject(BalanceRequest $request, User $approver, string $reason): BalanceRequest
    {
        $this->guardPending($request);

        if ($approver->id === $request->requested_by) {
            throw new SelfApprovalException;
        }

        $request->update([
            'status' => BalanceRequestStatus::Rejected,
            'decided_by' => $approver->id,
            'decided_at' => now(),
            'note' => $reason,
        ]);

        $this->notifyDecision($request);

        return $request;
    }

    /**
     * An admin acts directly on behalf of a different beneficiary: create and
     * approve in one step. Only valid when the acting user is not the
     * beneficiary (BUILD.md §9) — that's the safety check here, not
     * requested_by !== decided_by, since both legitimately equal the admin.
     */
    public function actDirectly(
        BalanceRequestKind $kind,
        User $beneficiary,
        int $amountCents,
        User $actingUser,
        ?CompanyAccount $companyAccount = null,
        ?string $note = null,
    ): BalanceRequest {
        if ($actingUser->id === $beneficiary->id) {
            throw new SelfApprovalException;
        }

        $request = $this->createRow($kind, $beneficiary, $amountCents, $actingUser, $companyAccount, $note);

        return $this->finalizeApproval($request, $actingUser, $note);
    }

    /**
     * commission_move when commission.move_requires_approval is off: instant
     * self-service, no separate approval step (BUILD.md §9).
     */
    public function moveCommissionInstant(User $agent, int $amountCents): BalanceRequest
    {
        $request = $this->createRow(BalanceRequestKind::CommissionMove, $agent, $amountCents, $agent, null, null);

        return $this->finalizeApproval($request, $agent, null);
    }

    private function guardPending(BalanceRequest $request): void
    {
        if ($request->status !== BalanceRequestStatus::Pending) {
            throw new RuntimeException('This request has already been decided.');
        }
    }

    private function finalizeApproval(BalanceRequest $request, User $decider, ?string $note): BalanceRequest
    {
        $approved = DB::transaction(function () use ($request, $decider, $note) {
            $entry = $this->postLedgerEntry($request, $decider);

            $request->update([
                'status' => BalanceRequestStatus::Approved,
                'decided_by' => $decider->id,
                'decided_at' => now(),
                'journal_entry_id' => $entry->id,
                'note' => $note ?? $request->note,
            ]);

            $this->notifyDecision($request);

            return $request;
        });

        // Only a balance top-up is worth an SMS; commission moves and
        // withdrawals are agent-side bookkeeping.
        if (in_array($approved->kind, [BalanceRequestKind::TopupPaid, BalanceRequestKind::TopupCredit], true)) {
            app(EmailNotifier::class)->topupApproved($approved);

            SendTemplatedSms::dispatch(
                SmsEvent::TopupApproved,
                extraValues: [
                    'agent_name' => $approved->user->name,
                    'amount_usd' => Money::format($approved->amount_cents),
                    'date' => now()->format('Y-m-d H:i'),
                ],
                overrideRecipients: array_filter([$approved->user->phone]),
            );
        }

        return $approved;
    }

    private function notifyDecision(BalanceRequest $request): void
    {
        $request->user->notify(new BalanceRequestDecided($request));

        if ($request->requestedBy->isNot($request->user)) {
            $request->requestedBy->notify(new BalanceRequestDecided($request));
        }
    }

    private function postLedgerEntry(BalanceRequest $request, User $decider): JournalEntry
    {
        $beneficiary = $request->user;
        $amount = $request->amount_cents;
        $company = $request->companyAccount;

        return match ($request->kind) {
            BalanceRequestKind::TopupPaid => $this->ledger->post(
                JournalType::TopupPaid,
                [
                    [$this->ledger->accountFor($company, LedgerAccountType::Company), $amount],
                    [$this->ledger->accountFor($beneficiary, LedgerAccountType::AgentSend), -$amount],
                ],
                $request,
                $decider
            ),

            BalanceRequestKind::TopupCredit => $this->postTopupCredit($request, $beneficiary, $amount, $decider),

            BalanceRequestKind::CreditRepayment => $this->ledger->post(
                JournalType::CreditRepayment,
                [
                    [$this->ledger->accountFor($company, LedgerAccountType::Company), $amount],
                    [$this->ledger->accountFor($beneficiary, LedgerAccountType::AgentCredit), -$amount],
                ],
                $request,
                $decider
            ),

            BalanceRequestKind::PayoutFunding => $this->ledger->post(
                JournalType::PayoutFunding,
                [
                    [$this->ledger->accountFor($beneficiary, LedgerAccountType::AgentPayout), $amount],
                    [$this->ledger->accountFor($company, LedgerAccountType::Company), -$amount],
                ],
                $request,
                $decider
            ),

            BalanceRequestKind::PayoutReturn => $this->ledger->post(
                JournalType::PayoutReturn,
                [
                    [$this->ledger->accountFor($company, LedgerAccountType::Company), $amount],
                    [$this->ledger->accountFor($beneficiary, LedgerAccountType::AgentPayout), -$amount],
                ],
                $request,
                $decider
            ),

            BalanceRequestKind::CommissionMove => $this->ledger->post(
                JournalType::CommissionMove,
                [
                    [$this->ledger->accountFor($beneficiary, LedgerAccountType::AgentCommission), $amount],
                    [$this->ledger->accountFor($beneficiary, LedgerAccountType::AgentSend), -$amount],
                ],
                $request,
                $decider
            ),

            BalanceRequestKind::CommissionWithdraw => $this->ledger->post(
                JournalType::CommissionWithdraw,
                [
                    [$this->ledger->accountFor($beneficiary, LedgerAccountType::AgentCommission), $amount],
                    [$this->ledger->accountFor($company, LedgerAccountType::Company), -$amount],
                ],
                $request,
                $decider
            ),
        };
    }

    /**
     * Blocked if credit owed + amount > max credit (user's own limit, else the
     * settings default; null = no limit). This is a business-policy ceiling,
     * checked ahead of the atomic ledger post — not to be confused with the
     * ledger's own never-negative floor guard on agent_credit.
     */
    private function postTopupCredit(BalanceRequest $request, User $beneficiary, int $amount, User $decider): JournalEntry
    {
        $creditAccount = $this->ledger->accountFor($beneficiary, LedgerAccountType::AgentCredit);
        $maxCents = $beneficiary->max_credit_cents ?? Settings::get('credit.default_max_cents');

        if ($maxCents !== null && $creditAccount->balance_cents + $amount > $maxCents) {
            throw MaxCreditExceededException::forUser($creditAccount->balance_cents, $amount, $maxCents);
        }

        return $this->ledger->post(
            JournalType::TopupCredit,
            [
                [$creditAccount, $amount],
                [$this->ledger->accountFor($beneficiary, LedgerAccountType::AgentSend), -$amount],
            ],
            $request,
            $decider
        );
    }
}
