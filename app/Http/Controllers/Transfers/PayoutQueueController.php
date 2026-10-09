<?php

namespace App\Http\Controllers\Transfers;

use App\Enums\LedgerAccountType;
use App\Enums\TransferStatus;
use App\Enums\UserType;
use App\Exceptions\Ledger\InsufficientBalanceException;
use App\Exceptions\Transfers\TransferStateException;
use App\Http\Controllers\Controller;
use App\Models\PayoutAccount;
use App\Models\Transfer;
use App\Models\User;
use App\Services\Ledger\LedgerService;
use App\Services\Transfers\PayoutQueueService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PayoutQueueController extends Controller
{
    public function __construct(
        private PayoutQueueService $queue,
        private LedgerService $ledger,
    ) {}

    public function index(Request $request): View
    {
        $this->authorize('queue.view');

        // Lazy expiry: the scheduler handles this every minute, but running
        // it on page load means an agent never sees a stale claim (BUILD.md §8).
        $this->queue->expireDueClaims();

        $user = $request->user();
        $isAdmin = $user->can('queue.force_assign');

        $queued = Transfer::query()
            ->where('status', TransferStatus::Queued)
            ->when(! $isAdmin, fn ($q) => $q->where('branch_id', $user->branch_id))
            ->with(['sendingAgent', 'branch'])
            ->oldest()
            ->get();

        $mine = Transfer::query()
            ->where('status', TransferStatus::Claimed)
            ->when(! $isAdmin, fn ($q) => $q->where('claimed_by', $user->id))
            ->with(['sendingAgent', 'branch', 'claimedBy'])
            ->orderBy('claim_expires_at')
            ->get();

        return view('transfers.queue', [
            'queued' => $queued,
            'claimed' => $mine,
            'payoutAccounts' => $user->payoutAccounts()->where('is_active', true)->with('accountType')->get(),
            'payoutBalance' => $user->type->pays()
                ? $this->ledger->accountFor($user, LedgerAccountType::AgentPayout)->balance_cents
                : null,
            'branchAgents' => $isAdmin
                ? User::query()->whereIn('type', [UserType::PayoutAgent, UserType::DualAgent])->where('is_active', true)->orderBy('name')->get()
                : collect(),
            'isAdmin' => $isAdmin,
        ]);
    }

    public function claim(Request $request, Transfer $transfer): RedirectResponse
    {
        $this->authorize('claim', $transfer);

        try {
            $this->queue->claim($transfer, $request->user());
        } catch (TransferStateException|InsufficientBalanceException $e) {
            return back()->withErrors(['status' => $e->getMessage()]);
        }

        return back()->with('status', __('Claimed — pay the receiver, then mark it paid.'));
    }

    public function markPaid(Request $request, Transfer $transfer): RedirectResponse
    {
        $this->authorize('markPaid', $transfer);

        $validated = $request->validate([
            'payout_account_id' => ['required', 'exists:payout_accounts,id'],
            'payout_reference' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $this->queue->markPaid(
                $transfer,
                $request->user(),
                PayoutAccount::findOrFail($validated['payout_account_id']),
                $validated['payout_reference'] ?? null,
            );
        } catch (TransferStateException|InsufficientBalanceException $e) {
            return back()->withErrors(['status' => $e->getMessage()]);
        }

        return back()->with('status', __('Marked paid.'));
    }

    public function release(Request $request, Transfer $transfer): RedirectResponse
    {
        $this->authorize('unassign', $transfer);

        try {
            $this->queue->release($transfer, $request->user());
        } catch (TransferStateException $e) {
            return back()->withErrors(['status' => $e->getMessage()]);
        }

        return back()->with('status', __('Returned to the queue.'));
    }

    public function forceAssign(Request $request, Transfer $transfer): RedirectResponse
    {
        $this->authorize('forceAssign', Transfer::class);

        $validated = $request->validate(['user_id' => ['required', 'exists:users,id']]);
        $agent = User::findOrFail($validated['user_id']);

        if (! $agent->type->pays() || ! $agent->is_active || $agent->branch_id !== $transfer->branch_id) {
            return back()->withErrors(['status' => __('Pick an active payout agent in this transfer\'s branch.')]);
        }

        try {
            $this->queue->forceAssign($transfer, $agent, $request->user());
        } catch (TransferStateException $e) {
            return back()->withErrors(['status' => $e->getMessage()]);
        }

        return back()->with('status', __('Assigned to :name.', ['name' => $agent->name]));
    }
}
