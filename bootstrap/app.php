<?php

use App\Http\Middleware\EnsureTwoFactorEnrolled;
use App\Http\Middleware\LogoutWhenIdle;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

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
        //
    })->create();
