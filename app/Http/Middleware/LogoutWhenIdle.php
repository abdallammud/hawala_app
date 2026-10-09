<?php

namespace App\Http\Middleware;

use App\Support\Settings;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Signs a user out after `security.idle_timeout_minutes` without a request.
 * The page also pings the server while the person is really working (typing
 * a long form counts), so only genuine inactivity runs the clock out.
 */
class LogoutWhenIdle
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! Auth::check()) {
            return $next($request);
        }

        $minutes = (int) Settings::get('security.idle_timeout_minutes', 15);
        $last = (int) $request->session()->get('last_activity_at', 0);

        if ($minutes > 0 && $last > 0 && now()->timestamp - $last > $minutes * 60) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            if ($request->expectsJson()) {
                abort(401, __('You were signed out because you were inactive.'));
            }

            return redirect()->route('login', ['idle' => 1]);
        }

        $request->session()->put('last_activity_at', now()->timestamp);

        return $next($request);
    }
}
