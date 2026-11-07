<?php

namespace App\Support;

use App\Enums\TransferStatus;
use App\Models\Transfer;
use App\Models\User;

/**
 * Builds the sidebar from config/menu.php: drops items the user may not see,
 * and marks exactly one of the rest active for the current route — however
 * many levels deep the page is (`transfers/1`, `users/3/edit`, `setup/branches`).
 */
class Menu
{
    /**
     * @return array<int, array{label:string,route:string,icon:string,permission:?string,active:bool}>
     */
    public static function items(?User $user): array
    {
        $items = array_values(array_filter(
            config('menu', []),
            fn (array $item) => empty($item['permission']) || ($user && $user->can($item['permission'])),
        ));

        $activeIndex = self::activeIndex($items, request()->route()?->getName());

        return array_map(
            fn (array $item, int $i) => $item + ['active' => $i === $activeIndex],
            $items,
            array_keys($items),
        );
    }

    /**
     * The phone's bottom bar: up to four shortcuts plus "More" (added by the
     * view). Items come from the same permission-filtered list as the sidebar,
     * so nobody gets a dead button. With a raised primary action it takes the
     * centre of the five slots, so three ranked tabs go around it.
     *
     * @return array{primary: ?array<string, mixed>, left: array<int, array<string, mixed>>, right: array<int, array<string, mixed>>}
     */
    public static function bottomTabs(?User $user): array
    {
        $items = self::items($user);

        $primary = collect($items)->first(fn (array $item) => ! empty($item['tab']['primary']));

        $ranked = collect($items)
            ->filter(fn (array $item) => isset($item['tab']['rank']))
            ->sortBy(fn (array $item) => $item['tab']['rank'])
            ->take($primary ? 3 : 4)
            ->values()
            ->map(fn (array $item) => $item + ['count' => self::badge($item['tab']['badge'] ?? null, $user)]);

        if (! $primary) {
            return ['primary' => null, 'left' => $ranked->all(), 'right' => []];
        }

        return [
            'primary' => $primary,
            'left' => $ranked->take(2)->all(),
            'right' => $ranked->slice(2)->values()->all(),
        ];
    }

    /** Live number shown on a tab; 0 hides the badge. */
    public static function badge(?string $key, ?User $user): int
    {
        if ($key !== 'queue' || ! $user) {
            return 0;
        }

        // Same scope as the Payout Queue page: staff see every branch, a payout agent their own.
        return Transfer::query()
            ->where('status', TransferStatus::Queued)
            ->when(! $user->can('queue.force_assign'), fn ($q) => $q->where('branch_id', $user->branch_id))
            ->count();
    }

    /**
     * The item whose `match` patterns fit the current route most closely, or
     * null when nothing fits (profile, notifications). Ties go to config order.
     *
     * @param  array<int, array<string, mixed>>  $items
     */
    private static function activeIndex(array $items, ?string $routeName): ?int
    {
        if (! $routeName) {
            return null;
        }

        $best = null;
        $bestScore = 0;

        foreach ($items as $i => $item) {
            foreach ($item['match'] ?? [$item['route']] as $pattern) {
                $score = self::score($pattern, $routeName);

                if ($score > $bestScore) {
                    $best = $i;
                    $bestScore = $score;
                }
            }
        }

        return $best;
    }

    /**
     * 0 = no match. An exact route name beats every wildcard; between
     * wildcards, the one with more fixed segments wins — so on
     * `transfers.create` the pattern `transfers.create` (exact) beats
     * `transfers.*`, while on `transfers.show` only `transfers.*` matches.
     */
    private static function score(string $pattern, string $routeName): int
    {
        if ($pattern === $routeName) {
            return 1000;
        }

        if (! str_contains($pattern, '*')) {
            return 0;
        }

        if (! fnmatch($pattern, $routeName)) {
            return 0;
        }

        return substr_count(rtrim(strstr($pattern, '*', true) ?: '', '.'), '.') + 1;
    }
}
