<?php

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\NewPasswordController;
use App\Http\Controllers\Auth\PasswordResetLinkController;
use App\Http\Controllers\Auth\TwoFactorChallengeController;
use App\Http\Controllers\Auth\TwoFactorSetupController;
use App\Http\Controllers\ProfileController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('login', [AuthenticatedSessionController::class, 'create'])->name('login');
    Route::post('login', [AuthenticatedSessionController::class, 'store']);

    Route::get('two-factor-challenge', [TwoFactorChallengeController::class, 'create'])->name('two-factor.challenge');
    Route::post('two-factor-challenge', [TwoFactorChallengeController::class, 'store'])->name('two-factor.challenge.store');

    Route::get('forgot-password', [PasswordResetLinkController::class, 'create'])->name('password.request');
    Route::post('forgot-password', [PasswordResetLinkController::class, 'store'])->name('password.email');

    Route::get('reset-password/{token}', [NewPasswordController::class, 'create'])->name('password.reset');
    Route::post('reset-password', [NewPasswordController::class, 'store'])->name('password.store');
});

Route::middleware('auth')->group(function () {
    Route::get('profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::put('profile/password', [ProfileController::class, 'updatePassword'])->name('profile.password');

    Route::get('two-factor/setup', [TwoFactorSetupController::class, 'show'])->name('two-factor.setup');
    Route::post('two-factor/setup', [TwoFactorSetupController::class, 'confirm'])->name('two-factor.confirm');
    Route::get('two-factor/recovery-codes', [TwoFactorSetupController::class, 'codes'])->name('two-factor.codes');
    Route::post('two-factor/recovery-codes', [TwoFactorSetupController::class, 'regenerate'])->name('two-factor.regenerate');
    Route::delete('two-factor', [TwoFactorSetupController::class, 'disable'])->name('two-factor.disable');

    // The page pings this while someone is working, so the idle timer only
    // runs out on real inactivity (the LogoutWhenIdle middleware does the rest).
    Route::post('session/keepalive', fn () => response()->noContent())->name('session.keepalive');

    Route::post('logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');
});
