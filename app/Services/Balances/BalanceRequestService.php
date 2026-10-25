<?php

namespace App\Services\Balances;

use App\Enums\BalanceRequestKind;
use App\Enums\BalanceRequestStatus;
use App\Enums\JournalType;
use App\Enums\LedgerAccountType;
use App\Enums\SmsEvent;
use App\Exceptions\Balances\AlreadyApprovedException;
use App\Exceptions\Balances\MaxCreditExceededException;
use App\Exceptions\Balances\SelfApprovalException;
use App\Jobs\SendTemplatedSms;
use App\Models\BalanceRequest;
use App\Models\CompanyAccount;
use App\Models\JournalEntry;
use App\Models\LedgerAccount;
use App\Models\User;
use App\Notifications\BalanceRequestApprovalRecorded;
use App\Notifications\BalanceRequestDecided;
use App\Notifications\BalanceRequestSubmitted;
use App\Services\Email\EmailNotifier;
use App\Services\Ledger\LedgerService;
use App\Support\LocalTime;
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

        $this->notifyApprovers($request);

        return $request;
    }

    private function notifyApprovers(BalanceRequest $request): void
    {
        User::permission($request->kind->approvePermission())
            ->where('id', '!=', $request->requested_by)
            ->each(fn (User $approver) => $approver->notify(new BalanceRequestSubmitted($request)));
    }

    /** Tell the approvers who still have to act that one approval is in. */
    private function notifyProgress(BalanceRequest $request, User $approver): void
    {
        $given = $request->approvals()->count();
        $done = $request->approvals()->pluck('user_id')->all();

        User::permission($request->kind->approvePermission())
            ->whereNotIn('id', [...$done, $request->requested_by, $request->user_id])
            ->each(fn (User $other) => $other->notify(new BalanceRequestApprovalRecorded($request, $approver, $given)));
    }

    private function guardApprover(BalanceRequest $request, User $approver): void
    {
        if ($approver->id === $request->requested_by) {
            throw new SelfApprovalException;
        }

        if ($request->approvals_required > 1 && $approver->id === $request->user_id) {
            throw new SelfApprovalException;
        }
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
            'approvals_required' => $kind->approvalsRequired(),
            'requested_by' => $requestedBy->id,
            'note' => $note,
        ]);
    }

    /**
     * Add one approval. The request posts to the ledger only when it has the
     * number it needs (credit top-ups: two different people). Maker ≠ checker:
     * the requester can't approve, and neither can the beneficiary of a
     * multi-approval request. The same person can't approve twice.
     */
    public function approve(BalanceRequest $request, User $approver, ?string $note = null): BalanceRequest
    {
        $final = DB::transaction(function () use ($request, $approver, $note) {
            $locked = BalanceRequest::query()->lockForUpdate()->findOrFail($request->id);

            $this->guardPending($locked);
            $this->guardApprover($locked, $approver);

            if ($locked->approvals()->where('user_id', $approver->id)->exists()) {
                throw new AlreadyApprovedException;
            }

            if ($locked->kind === BalanceRequestKind::TopupCredit) {
                $this->assertWithinCreditLimit($locked->user, $locked->amount_cents);
            }

            $locked->approvals()->create(['user_id' => $approver->id, 'note' => $note]);

            if ($locked->approvals()->count() < $locked->approvals_required) {
                return false;
            }

            $this->postApproved($locked, $approver, $note);

            return true;
        });

        $request->refresh();

        if ($final) {
            $this->afterApproved($request);
        } else {
            $this->notifyProgress($request, $approver);
        }

        return $request;
    }

    /** One rejection ends the request, however many approvals it already has. */
    public function reject(BalanceRequest $request, User $approver, string $reason): BalanceRequest
    {
        DB::transaction(function () use ($request, $approver, $reason) {
            $locked = BalanceRequest::query()->lockForUpdate()->findOrFail($request->id);

            $this->guardPending($locked);
            $this->guardApprover($locked, $approver);

            $locked->update([
                'status' => BalanceRequestStatus::Rejected,
                'decided_by' => $approver->id,
                'decided_at' => now(),
                'note' => $reason,
            ]);
        });

        $request->refresh();
        $this->notifyDecision($request);

        return $request;
    }

    /**
     * An admin acts directly on behalf of a different beneficiary: create and
     * approve in one step. Only valid when the acting user is not the
     * beneficiary (BUILD.md §9) — that's the safety check here, not
     * requested_by !== decided_by, since both legitimately equal the admin.
     *
     * A kind that needs more than one approval (credit) is never finished in
     * one step: the admin's entry is only the request, and others must approve.
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

        if ($request->approvals_required > 1) {
            $this->notifyApprovers($request);

            return $request;
        }

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

    /** Single-step finish (admin on behalf, instant commission move). */
    private function finalizeApproval(BalanceRequest $request, User $decider, ?string $note): BalanceRequest
    {
        DB::transaction(function () use ($request, $decider, $note) {
            $request->approvals()->create(['user_id' => $decider->id, 'note' => $note]);
            $this->postApproved($request, $decider, $note);
        });

        $this->afterApproved($request);

        return $request;
    }

    /** Post the ledger entry and mark approved. Runs inside the caller's transaction. */
    private function postApproved(BalanceRequest $request, User $decider, ?string $note): void
    {
        $entry = $this->postLedgerEntry($request, $decider);

        $request->update([
            'status' => BalanceRequestStatus::Approved,
            'decided_by' => $decider->id,
            'decided_at' => now(),
            'journal_entry_id' => $entry->id,
            'note' => $note ?? $request->note,
        ]);
    }

    private function afterApproved(BalanceRequest $approved): void
    {
        $this->notifyDecision($approved);

        // Only a balance top-up is worth an SMS; commission moves and
        // withdrawals are agent-side bookkeeping.
        if (in_array($approved->kind, [BalanceRequestKind::TopupPaid, BalanceRequestKind::TopupCredit], true)) {
            app(EmailNotifier::class)->topupApproved($approved);

            SendTemplatedSms::dispatch(
                SmsEvent::TopupApproved,
                extraValues: [
                    'agent_name' => $approved->user->name,
                    'amount_usd' => Money::format($approved->amount_cents),
                    'date' => LocalTime::now()->format('Y-m-d H:i'),
                ],
                overrideRecipients: array_filter([$approved->user->phone]),
            );
        }
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
        $creditAccount = $this->assertWithinCreditLimit($beneficiary, $amount);

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

    /** Throws if the credit would pass the limit; returns the credit account it checked. */
    private function assertWithinCreditLimit(User $beneficiary, int $amount): LedgerAccount
    {
        $creditAccount = $this->ledger->accountFor($beneficiary, LedgerAccountType::AgentCredit);
        $maxCents = $beneficiary->max_credit_cents ?? Settings::get('credit.default_max_cents');

        if ($maxCents !== null && $creditAccount->balance_cents + $amount > $maxCents) {
            throw MaxCreditExceededException::forUser($creditAccount->balance_cents, $amount, $maxCents);
        }

        return $creditAccount;
    }
}
