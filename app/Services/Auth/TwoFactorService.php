<?php

namespace App\Services\Auth;

use App\Models\User;
use App\Support\Settings;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;
use RuntimeException;

/**
 * Time-based one-time passwords for Google Authenticator and compatible apps
 * (Microsoft Authenticator, Authy, 1Password…). Nothing is sent anywhere:
 * the phone and the server share a secret and both compute the same code.
 */
class TwoFactorService
{
    public const RECOVERY_CODE_COUNT = 8;

    public function __construct(private Google2FA $google2fa) {}

    /** Whether every user must have 2FA to use the system (Settings → Security). */
    public function required(): bool
    {
        return (bool) Settings::get('security.two_factor_required', true);
    }

    /** The secret to scan, created on first visit and kept until confirmed. */
    public function beginSetup(User $user): string
    {
        if ($user->hasTwoFactorEnabled()) {
            throw new RuntimeException('Two-factor authentication is already on.');
        }

        if (blank($user->two_factor_secret)) {
            $user->forceFill(['two_factor_secret' => $this->google2fa->generateSecretKey(32)])->save();
        }

        return $user->two_factor_secret;
    }

    /** Inline SVG of the QR code for the authenticator app. */
    public function qrSvg(User $user, string $secret): string
    {
        $url = $this->google2fa->getQRCodeUrl(
            (string) Settings::get('general.company_name', config('app.name')),
            $user->email,
            $secret,
        );

        $svg = (new Writer(new ImageRenderer(new RendererStyle(220, 1), new SvgImageBackEnd)))->writeString($url);

        // Drop the XML prolog so the SVG can sit inside an HTML page.
        return (string) preg_replace('/^<\?xml[^>]*\?>\s*/', '', $svg);
    }

    /**
     * Check the first code from the phone. On success 2FA turns on and the
     * one-time recovery codes are returned — the only time they are shown.
     *
     * @return list<string>|null null when the code was wrong
     */
    public function confirm(User $user, string $code): ?array
    {
        if ($user->hasTwoFactorEnabled() || blank($user->two_factor_secret)) {
            return null;
        }

        $step = $this->checkCode($user, $code);

        if ($step === null) {
            return null;
        }

        $codes = $this->newRecoveryCodes();

        $user->forceFill([
            'two_factor_confirmed_at' => now(),
            'two_factor_last_step' => $step,
            'two_factor_recovery_codes' => $this->hash($codes),
        ])->save();

        return $codes;
    }

    /** A login code from the app. A code that was already used is refused. */
    public function verify(User $user, string $code): bool
    {
        if (! $user->hasTwoFactorEnabled()) {
            return false;
        }

        $step = $this->checkCode($user, $code);

        if ($step === null) {
            return false;
        }

        $user->forceFill(['two_factor_last_step' => $step])->save();

        return true;
    }

    /** One of the printed recovery codes; each works once. */
    public function useRecoveryCode(User $user, string $input): bool
    {
        $hash = hash('sha256', $this->normalize($input));
        $remaining = (array) $user->two_factor_recovery_codes;

        foreach ($remaining as $i => $stored) {
            if (hash_equals($stored, $hash)) {
                unset($remaining[$i]);
                $user->forceFill(['two_factor_recovery_codes' => array_values($remaining)])->save();

                return true;
            }
        }

        return false;
    }

    /** @return list<string> the new codes, shown once; the old ones stop working */
    public function regenerateRecoveryCodes(User $user): array
    {
        $codes = $this->newRecoveryCodes();

        $user->forceFill(['two_factor_recovery_codes' => $this->hash($codes)])->save();

        return $codes;
    }

    public function recoveryCodesLeft(User $user): int
    {
        return count((array) $user->two_factor_recovery_codes);
    }

    /** Back to "not set up": used when a user loses their phone or turns it off. */
    public function reset(User $user): void
    {
        $user->forceFill([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
            'two_factor_last_step' => null,
        ])->save();
    }

    /** @return int|null the matched time step, or null */
    private function checkCode(User $user, string $code): ?int
    {
        $code = preg_replace('/\D+/', '', $code);

        if (strlen($code) !== 6) {
            return null;
        }

        // verifyKeyNewer refuses any step at or before the last one accepted.
        // It returns the matched step only when given an old step to compare
        // with (with null it answers plain `true`), so a first-ever code
        // compares against 0.
        $step = $this->google2fa->verifyKeyNewer($user->two_factor_secret, $code, $user->two_factor_last_step ?? 0, 1);

        return is_int($step) ? $step : null;
    }

    /** @return list<string> like "k3x9a-p2m7q" */
    private function newRecoveryCodes(): array
    {
        return collect(range(1, self::RECOVERY_CODE_COUNT))
            ->map(fn () => Str::lower(Str::random(5)).'-'.Str::lower(Str::random(5)))
            ->all();
    }

    /** @param  list<string>  $codes */
    private function hash(array $codes): array
    {
        return array_map(fn (string $c) => hash('sha256', $this->normalize($c)), $codes);
    }

    private function normalize(string $code): string
    {
        return Str::lower(preg_replace('/[^a-z0-9]/i', '', $code));
    }
}
