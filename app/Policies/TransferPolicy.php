<?php

namespace App\Policies;

use App\Enums\TransferStatus;
use App\Models\Transfer;
use App\Models\User;

class TransferPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canAny(['transfers.view_own', 'transfers.view_branch', 'transfers.view_all']);
    }

    public function view(User $user, Transfer $transfer): bool
    {
        if ($user->can('transfers.view_all')) {
            return true;
        }

        if ($user->can('transfers.view_own') && $transfer->sending_agent_id === $user->id) {
            return true;
        }

        return $user->can('transfers.view_branch')
            && $user->branch_id !== null
            && $transfer->branch_id === $user->branch_id;
    }

    public function create(User $user): bool
    {
        return $user->can('transfers.create');
    }

    /** Cancel outright: own queued transfer, or any open transfer as an admin. */
    public function cancel(User $user, Transfer $transfer): bool
    {
        if (! $transfer->status->isOpen()) {
            return false;
        }

        if ($user->can('transfers.cancel_any')) {
            return true;
        }

        return $user->can('transfers.cancel_own')
            && $transfer->sending_agent_id === $user->id
            && $transfer->status === TransferStatus::Queued;
    }

    /** Ask an admin to cancel: own transfer that a payout agent already claimed. */
    public function requestCancel(User $user, Transfer $transfer): bool
    {
        return $user->can('transfers.request_cancel')
            && $transfer->sending_agent_id === $user->id
            && $transfer->status === TransferStatus::Claimed;
    }

    public function approveCancel(User $user): bool
    {
        return $user->can('transfers.approve_cancel');
    }

    public function approveLarge(User $user): bool
    {
        return $user->can('transfers.approve_large');
    }

    public function claim(User $user, Transfer $transfer): bool
    {
        return $user->can('queue.claim')
            && $transfer->status === TransferStatus::Queued
            && $user->branch_id !== null
            && $transfer->branch_id === $user->branch_id;
    }

    public function markPaid(User $user, Transfer $transfer): bool
    {
        if ($transfer->status !== TransferStatus::Claimed) {
            return false;
        }

        if ($user->can('queue.force_assign')) {
            return true; // an admin may complete a payout on an agent's behalf
        }

        return $user->can('queue.mark_paid') && $transfer->claimed_by === $user->id;
    }

    public function forceAssign(User $user): bool
    {
        return $user->can('queue.force_assign');
    }

    public function unassign(User $user, Transfer $transfer): bool
    {
        if ($transfer->status !== TransferStatus::Claimed) {
            return false;
        }

        return $user->can('queue.unassign') || $transfer->claimed_by === $user->id;
    }

    public function recordCollection(User $user, Transfer $transfer): bool
    {
        if (! $transfer->is_uncollected || $transfer->fully_collected_at !== null) {
            return false;
        }

        return $user->can('transfers.view_all')
            || ($user->can('transfers.record_collection') && $transfer->sending_agent_id === $user->id);
    }
}
