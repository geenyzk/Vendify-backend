<?php

namespace App\Classes\SerivceControl;

use App\Models\ServiceControl;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

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
        if (!$userID || $pin === null || trim($pin) === '') {
            return false;
        }

        $user = User::find($userID);

        if (!$user || !$user->pin) {
            return false;
        }

        return Hash::check($pin, $user->pin);
    }
}
