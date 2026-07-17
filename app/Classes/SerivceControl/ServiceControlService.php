<?php

namespace App\Classes\SerivceControl;

use App\Models\ServiceControl;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use App\Support\AuditLogger;
use Illuminate\Http\Exceptions\HttpResponseException;

class ServiceControlService
{
    /**
     * Create a new class instance.
     */
    public function __construct()
    {
        //
    }

    static function verify(string|int|null $userID, ?string $pin=""){
       if (!ServiceControl::requiresPin()) {
            return true; // No pin verification required
        }

        return self::verifyTransactionPin($userID, $pin);
    }

    static function verifyTransactionPin(string|int|null $userID, ?string $pin = ""): bool
    {
        $ip = function_exists('request') ? request()->ip() : 'console';
        $key = 'transaction-pin|' . ($userID ?: 'guest') . '|' . $ip;
        if (RateLimiter::tooManyAttempts($key, 5)) {
            $user = $userID ? User::find($userID) : null;
            AuditLogger::record(
                'pin_verification_rate_limited',
                subject: $user,
                actor: $user,
                description: 'Transaction PIN verification was rate limited.',
            );
            throw new HttpResponseException(response()->json([
                'message' => 'Too many PIN attempts. Please wait before trying again.',
                'success' => false,
                'errors' => ['pin' => ['Too many attempts.']],
                'type' => 'error',
            ], 429));
        }

        if (!$userID || $pin === null || trim($pin) === '') {
            RateLimiter::hit($key, 300);
            return false;
        }

        $user = User::find($userID);

        if (!$user || !$user->pin) {
            RateLimiter::hit($key, 300);
            return false;
        }

        if (!Hash::check($pin, $user->pin)) {
            RateLimiter::hit($key, 300);
            AuditLogger::record(
                'pin_verification_failed',
                subject: $user,
                actor: $user,
                description: 'Transaction PIN verification failed.',
            );
            return false;
        }

        RateLimiter::clear($key);
        return true;
    }
}
