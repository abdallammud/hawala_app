<?php

use App\Http\Middleware\EnsureTwoFactorEnrolled;
use App\Http\Middleware\LogoutWhenIdle;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Order matters: sign out the idle first, then make sure whoever is
        // left has 2FA.
        $middleware->web(append: [
            LogoutWhenIdle::class,
            EnsureTwoFactorEnrolled::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // A form whose page outlived the session fails the CSRF check. The stock
        // "Page Expired" screen has no way out, and an installed app has no
        // reload button, so resubmitting the same stale page failed forever.
        // Send people to a fresh page instead.
        $exceptions->render(function (HttpException $e, Request $request) {
            if ($e->getStatusCode() !== 419 || $request->expectsJson()) {
                return null;
            }

            // No session left: the way forward is a fresh login page.
            if (! $request->user()) {
                return redirect()->route('login')
                    ->with('error', __('Your session timed out. Please log in again.'));
            }

            // Still signed in (an old tab, say): back to the page, which now has a fresh token.
            return back()->withInput()
                ->with('error', __('That page was open for too long and nothing was saved. Please try again.'));
        });
    })->create();
