<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class AuthenticatedSessionController extends Controller
{
    public function create(): View
    {
        return view('auth.login');
    }

    public function store(LoginRequest $request): RedirectResponse
    {
        $user = $request->authenticate();

        // Password right, but the account has 2FA: nobody is signed in yet.
        // Park the user id in the session and ask for the code.
        if ($user->hasTwoFactorEnabled()) {
            $request->session()->regenerate();
            $request->session()->put('two_factor', [
                'user_id' => $user->id,
                'remember' => $request->boolean('remember'),
                'started_at' => now()->timestamp,
            ]);

            return redirect()->route('two-factor.challenge');
        }

        return $this->signIn($request, $user, $request->boolean('remember'));
    }

    /** The one place a user actually becomes signed in. */
    public static function signIn(Request $request, User $user, bool $remember): RedirectResponse
    {
        Auth::guard('web')->login($user, $remember);

        $request->session()->regenerate();
        $request->session()->forget('two_factor');

        $user->forceFill(['last_login_at' => now()])->save();

        return redirect()->intended(route('dashboard'));
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/login');
    }
}
