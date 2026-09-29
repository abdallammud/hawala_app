<?php

namespace App\Services\Balances;

use App\Enums\AdjustmentRequestStatus;
use App\Enums\JournalType;
use App\Exceptions\Balances\SelfApprovalException;
use App\Models\AdjustmentRequest;
use App\Models\LedgerAccount;
use App\Models\User;
use App\Services\Ledger\LedgerService;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Manual adjustments between any two ledger accounts — BUILD.md §4's
 * manual_adjustment journal type. Always maker ≠ checker (CLAUDE.md
 * access control rules), no "admin acts directly" shortcut: an
 * adjustment always goes through a separate approver.
 */
class AdjustmentService
{
    public function __construct(private LedgerService $ledger) {}

    public function submit(LedgerAccount $debit, LedgerAccount $credit, int $amountCents, string $reason, User $requestedBy): AdjustmentRequest
    {
        return AdjustmentRequest::create([
            'debit_ledger_account_id' => $debit->id,
            'credit_ledger_account_id' => $credit->id,
            'amount_cents' => $amountCents,
            'reason' => $reason,
            'status' => AdjustmentRequestStatus::Pending,
            'requested_by' => $requestedBy->id,
        ]);
    }

    public function approve(AdjustmentRequest $request, User $approver): AdjustmentRequest
    {
        $this->guardPending($request);

        if ($approver->id === $request->requested_by) {
            throw new SelfApprovalException;
        }

        return DB::transaction(function () use ($request, $approver) {
            $entry = $this->ledger->post(
                JournalType::ManualAdjustment,
                [
                    [$request->debitLedgerAccount, $request->amount_cents],
                    [$request->creditLedgerAccount, -$request->amount_cents],
                ],
                $request,
                $approver,
                $approver,
                $request->reason
            );

            $request->update([
                'status' => AdjustmentRequestStatus::Approved,
                'decided_by' => $approver->id,
                'decided_at' => now(),
                'journal_entry_id' => $entry->id,
            ]);

            return $request;
        });
    }

    public function reject(AdjustmentRequest $request, User $approver, string $reason): AdjustmentRequest
    {
        $this->guardPending($request);

        if ($approver->id === $request->requested_by) {
            throw new SelfApprovalException;
        }

        $request->update([
            'status' => AdjustmentRequestStatus::Rejected,
            'decided_by' => $approver->id,
            'decided_at' => now(),
            'decision_note' => $reason,
        ]);

        return $request;
    }

    private function guardPending(AdjustmentRequest $request): void
    {
        if ($request->status !== AdjustmentRequestStatus::Pending) {
            throw new RuntimeException('This adjustment has already been decided.');
        }
    }
}
