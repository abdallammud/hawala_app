<?php

namespace App\Services\Banners;

use App\Enums\UserType;
use App\Models\Branch;
use App\Models\DestinationAnnouncement;
use App\Models\User;
use App\Support\Settings;
use Illuminate\Support\Collection;

/**
 * Feeds the "you can now send money to …" banner shown to sending agents.
 *
 * A place is only news the first time it gets someone to pay out there, so
 * adding a second agent to Mogadishu says nothing. Plain notices: they link
 * nowhere and carry no money.
 */
class DestinationAnnouncer
{
    /** Call after a user is created, moved, retyped or reactivated. */
    public function userSaved(User $user): void
    {
        // A just-created model lacks the column defaults (is_active), so read the real row.
        $user = $user->fresh();

        if (! $user || ! $user->is_active || ! $user->type->pays() || ! $user->branch_id) {
            return;
        }

        $branch = Branch::query()->payoutPoints()->find($user->branch_id);

        if (! $branch) {
            return;
        }

        $othersAlreadyPay = User::query()
            ->where('branch_id', $branch->id)
            ->where('is_active', true)
            ->whereIn('type', collect(UserType::cases())->filter->pays()->map->value->all())
            ->whereKeyNot($user->id)
            ->exists();

        if ($othersAlreadyPay || $this->recent()->where('branch_id', $branch->id)->exists()) {
            return;
        }

        DestinationAnnouncement::query()->create(['branch_id' => $branch->id]);
    }

    /**
     * Places to announce right now: announced within the window, and still a
     * destination someone can actually pick.
     *
     * @return Collection<int, string>
     */
    public function placesToShow(): Collection
    {
        return $this->recent()
            ->with('branch')
            ->latest('created_at')
            ->get()
            ->map->branch
            ->filter(fn (?Branch $branch) => $branch && $branch->is_active && $branch->is_payout_point)
            ->map(fn (Branch $branch) => $branch->city ?: $branch->name)
            ->unique()
            ->values();
    }

    private function recent()
    {
        $days = max(1, (int) Settings::get('banners.new_destination_days', 7));

        return DestinationAnnouncement::query()->where('created_at', '>=', now()->subDays($days));
    }
}
