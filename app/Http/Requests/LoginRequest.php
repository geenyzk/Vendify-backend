<?php

namespace App\Http\Requests;

use Illuminate\Auth\Events\Lockout;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use App\Models\User;
use App\Support\AuditLogger;

class LoginRequest extends FormRequest
{
    private const MAX_ATTEMPTS = 5;
    private const DECAY_SECONDS = 900;

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'login' => ['required', 'string'],
            'password' => ['required', 'string'],
            'remember' => ['sometimes', 'boolean'],
            'client_type' => ['sometimes', 'in:web,mobile'],
        ];
    }

    /**
     * Attempt to authenticate the request's credentials.
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function authenticate(): User
    {
        $this->ensureIsNotRateLimited();
        $login  = $this->input('login');

        $user = User::where("email", $login)
            ->orWhere('phone', $login)
            ->orWhere('username', $login)
            ->first();

        if (!$user) {
            RateLimiter::hit($this->throttleKey(), self::DECAY_SECONDS);
            AuditLogger::record('login_failed', description: 'A sign-in attempt failed.');
            throw ValidationException::withMessages([
                'login' => trans('auth.failed'),
            ]);
        }

        // The identifier lookup already loaded the user. Verify its hash and
        // establish the session directly instead of querying the user again
        // through Auth::attempt().
        if (!Hash::check($this->input('password'), $user->password)
            || $user->is_active === false
            || !$user->isActive()) {
            RateLimiter::hit($this->throttleKey(), self::DECAY_SECONDS);
            AuditLogger::record(
                'login_failed',
                subject: $user,
                description: 'A sign-in attempt failed.',
            );

            throw ValidationException::withMessages([
                'login' => trans('auth.failed'),
            ]);
        }

        $isMobile = $this->input('client_type') === 'mobile'
            || $this->header('X-Client-Platform') === 'app';
        if (!$isMobile) {
            Auth::login($user, $this->boolean('remember'));
        }

        RateLimiter::clear($this->throttleKey());
        return $user;
    }

    /**
     * Ensure the login request is not rate limited.
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function ensureIsNotRateLimited(): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey(), self::MAX_ATTEMPTS)) {
            return;
        }

        event(new Lockout($this));

        $seconds = RateLimiter::availableIn($this->throttleKey());

        throw ValidationException::withMessages([
            'login' => trans('auth.throttle', [
                'seconds' => $seconds,
                'minutes' => ceil($seconds / 60),
            ]),
        ]);
    }

    /**
     * Get the rate limiting throttle key for the request.
     */
    public function throttleKey(): string
    {
        return Str::transliterate(Str::lower($this->string('login')) . '|' . $this->ip());
    }
}
