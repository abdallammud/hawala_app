<?php

namespace App\Services;

use App\Enums\CommissionType;
use App\Models\User;
use App\Support\Settings;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * BUILD.md §6. Rule resolution: the user's own override if set, else the
 * settings default. Shares are always rounded down to the cent — the
 * company gets the remainder (CLAUDE.md money rule 9).
 */
class CommissionCalculator
{
    public function sendingShare(User $agent, int $feeCents): array
    {
        [$type, $value] = $this->resolve(
            $agent->send_commission_type,
            $agent->send_commission_value,
            'commission.send_type',
            'commission.send_value',
        );

        return $this->compute($type, $value, $feeCents);
    }

    /** Uses the payer's (payout agent's) rule. Percent is of the remaining fee, not the whole fee. */
    public function payoutShare(User $payer, int $feeCents, int $sendingShareCents): array
    {
        [$type, $value] = $this->resolve(
            $payer->payout_commission_type,
            $payer->payout_commission_value,
            'commission.payout_type',
            'commission.payout_value',
        );

        $remaining = $feeCents - $sendingShareCents;

        return $this->compute($type, $value, $remaining);
    }

    public function companyShare(int $feeCents, int $sendingShareCents, int $payoutShareCents): int
    {
        return max(0, $feeCents - $sendingShareCents - $payoutShareCents);
    }

    private function resolve(?CommissionType $userType, ?int $userValue, string $settingTypeKey, string $settingValueKey): array
    {
        $type = $userType ?? CommissionType::from(Settings::get($settingTypeKey, 'percent'));
        $value = $userValue ?? (int) Settings::get($settingValueKey, 0);

        return [$type, $value];
    }

    private function compute(CommissionType $type, int $value, int $baseCents): array
    {
        $share = match ($type) {
            CommissionType::Percent => BigDecimal::of($baseCents)
                ->multipliedBy($value)
                ->dividedBy(10000, 0, RoundingMode::FLOOR)
                ->toInt(),
            CommissionType::Flat => min($value, $baseCents),
            CommissionType::None => 0,
        };

        return [
            'share_cents' => $share,
            'snapshot' => [
                'type' => $type->value,
                'value' => $value,
                'capped' => $type === CommissionType::Flat && $value > $baseCents,
            ],
        ];
    }
}
