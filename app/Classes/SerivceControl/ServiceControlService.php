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

    static function verify(string $userID, ?string $pin=""){
       if (!ServiceControl::requiresPin()) {
            return true; // No pin verification required
        }

        $user = User::find($userID);

        if (!$user || !$user->pin || !$pin) {
            return false;
        }

        return Hash::check($pin, $user->pin);
    }
}
