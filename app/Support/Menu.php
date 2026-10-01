<?php

namespace App\Support;

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
