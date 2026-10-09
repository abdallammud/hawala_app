<?php

namespace App\Http\Middleware;

use App\Services\Auth\TwoFactorService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * While the company requires 2FA, a signed-in user without it can do nothing
 * but set it up (or log out). That is how "set it up at first login" works.
 */
class EnsureTwoFactorEnrolled
{
    public function __construct(private TwoFactorService $twoFactor) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (
            $user === null
            || ! $this->twoFactor->required()
            || $user->hasTwoFactorEnabled()
            || $request->routeIs('two-factor.*', 'logout')
        ) {
            return $next($request);
        }

        if ($request->expectsJson()) {
            abort(403, __('Set up two-factor authentication first.'));
        }

        return redirect()->route('two-factor.setup');
    }
}
