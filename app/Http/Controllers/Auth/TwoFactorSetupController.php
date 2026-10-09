<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\Auth\TwoFactorService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/** Setting up (and, if allowed, turning off) the authenticator app. */
class TwoFactorSetupController extends Controller
{
    public function __construct(private TwoFactorService $twoFactor) {}

    public function show(Request $request): View|RedirectResponse
    {
        $user = $request->user();

        if ($user->hasTwoFactorEnabled()) {
            return redirect()->route('profile.edit');
        }

        $secret = $this->twoFactor->beginSetup($user);

        return view('auth.two-factor-setup', [
            'secret' => $secret,
            'qr' => $this->twoFactor->qrSvg($user, $secret),
            'required' => $this->twoFactor->required(),
        ]);
    }

    public function confirm(Request $request): RedirectResponse
    {
        $request->validate(['code' => ['required', 'string', 'max:16']]);

        $user = $request->user();
        $key = 'two-factor-setup|'.$user->id;

        if (RateLimiter::tooManyAttempts($key, 8)) {
            throw ValidationException::withMessages([
                'code' => __('Too many attempts. Please try again in :seconds seconds.', ['seconds' => RateLimiter::availableIn($key)]),
            ]);
        }

        $codes = $this->twoFactor->confirm($user, $request->string('code')->toString());

        if ($codes === null) {
            RateLimiter::hit($key);

            throw ValidationException::withMessages([
                'code' => __('That code is not right. Make sure the time on your phone is correct and try the next code.'),
            ]);
        }

        RateLimiter::clear($key);
        activity()->causedBy($user)->performedOn($user)->log('Two-factor authentication turned on');

        return redirect()->route('two-factor.codes')->with('recovery_codes', $codes);
    }

    /** Shown once, straight after setup or after making new codes. */
    public function codes(Request $request): View|RedirectResponse
    {
        $codes = $request->session()->get('recovery_codes');

        if (! is_array($codes)) {
            return redirect()->route('profile.edit');
        }

        // Keep them through a refresh of this one page, then they are gone.
        $request->session()->reflash();

        return view('auth.two-factor-codes', ['codes' => $codes]);
    }

    public function regenerate(Request $request): RedirectResponse
    {
        $request->validate(['current_password' => ['required', 'current_password']]);

        $user = $request->user();

        abort_unless($user->hasTwoFactorEnabled(), 404);

        $codes = $this->twoFactor->regenerateRecoveryCodes($user);
        activity()->causedBy($user)->performedOn($user)->log('Recovery codes regenerated');

        return redirect()->route('two-factor.codes')->with('recovery_codes', $codes);
    }

    /** Only when the company does not insist on 2FA. */
    public function disable(Request $request): RedirectResponse
    {
        $request->validate(['current_password' => ['required', 'current_password']]);

        abort_if($this->twoFactor->required(), 403);

        $this->twoFactor->reset($request->user());
        activity()->causedBy($request->user())->performedOn($request->user())->log('Two-factor authentication turned off');

        return redirect()->route('profile.edit')->with('status', __('Two-factor authentication is off.'));
    }
}
