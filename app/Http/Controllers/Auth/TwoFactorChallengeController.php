<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Auth\TwoFactorService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/** Step two of logging in: the 6-digit code (or a recovery code). */
class TwoFactorChallengeController extends Controller
{
    /** How long after the password a user has to enter the code. */
    private const MINUTES = 10;

    public function __construct(private TwoFactorService $twoFactor) {}

    public function create(Request $request): View|RedirectResponse
    {
        if ($this->pendingUser($request) === null) {
            return redirect()->route('login');
        }

        return view('auth.two-factor-challenge');
    }

    public function store(Request $request): RedirectResponse
    {
        $user = $this->pendingUser($request);

        if ($user === null) {
            return redirect()->route('login')->withErrors(['login' => __('Your login timed out. Please start again.')]);
        }

        $request->validate([
            'code' => ['nullable', 'string', 'max:32'],
            'recovery_code' => ['nullable', 'string', 'max:32'],
        ]);

        $key = 'two-factor|'.$user->id.'|'.$request->ip();

        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages([
                'code' => __('Too many attempts. Please try again in :seconds seconds.', ['seconds' => RateLimiter::availableIn($key)]),
            ]);
        }

        $ok = $request->filled('recovery_code')
            ? $this->twoFactor->useRecoveryCode($user, $request->string('recovery_code')->toString())
            : $this->twoFactor->verify($user, $request->string('code')->toString());

        if (! $ok) {
            RateLimiter::hit($key);

            throw ValidationException::withMessages([
                $request->filled('recovery_code') ? 'recovery_code' : 'code' => __('That code is not valid. Check your authenticator app and try again.'),
            ]);
        }

        RateLimiter::clear($key);

        $remember = (bool) data_get($request->session()->get('two_factor'), 'remember', false);

        if ($request->filled('recovery_code')) {
            activity()->causedBy($user)->performedOn($user)->log('Signed in with a recovery code');
        }

        $response = AuthenticatedSessionController::signIn($request, $user, $remember);

        if ($request->filled('recovery_code') && $this->twoFactor->recoveryCodesLeft($user) <= 2) {
            $response->with('status', __('You are running low on recovery codes. Make new ones on your profile page.'));
        }

        return $response;
    }

    /** The user who passed the password step, if that was recent enough. */
    private function pendingUser(Request $request): ?User
    {
        $pending = $request->session()->get('two_factor');

        if (! is_array($pending) || now()->timestamp - ($pending['started_at'] ?? 0) > self::MINUTES * 60) {
            $request->session()->forget('two_factor');

            return null;
        }

        $user = User::query()->find($pending['user_id'] ?? 0);

        return $user && $user->is_active && $user->hasTwoFactorEnabled() ? $user : null;
    }
}
