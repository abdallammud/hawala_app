<?php

namespace App\Http\Requests\Auth;

use App\Models\User;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'login' => ['required', 'string'],
            'password' => ['required', 'string'],
        ];
    }

    /**
     * Check the password and return the user — without signing them in. The
     * controller signs them in, or sends them to the 2FA challenge first.
     */
    public function authenticate(): User
    {
        $this->ensureIsNotRateLimited();

        $field = str_contains($this->string('login'), '@') ? 'email' : 'phone';
        $provider = Auth::guard('web')->getProvider();

        $user = $provider->retrieveByCredentials([$field => $this->string('login')->toString()]);

        if (! $user || ! $provider->validateCredentials($user, ['password' => $this->string('password')->toString()])) {
            RateLimiter::hit($this->throttleKey());

            throw ValidationException::withMessages([
                'login' => __('These credentials do not match our records.'),
            ]);
        }

        /** @var User $user */
        if (! $user->is_active) {
            throw ValidationException::withMessages([
                'login' => __('This account has been deactivated. Contact an administrator.'),
            ]);
        }

        RateLimiter::clear($this->throttleKey());

        return $user;
    }

    public function ensureIsNotRateLimited(): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey(), 5)) {
            return;
        }

        event(new Lockout($this));

        $seconds = RateLimiter::availableIn($this->throttleKey());

        throw ValidationException::withMessages([
            'login' => __('Too many attempts. Please try again in :seconds seconds.', ['seconds' => $seconds]),
        ]);
    }

    public function throttleKey(): string
    {
        return Str::transliterate(Str::lower($this->string('login')).'|'.$this->ip());
    }
}
