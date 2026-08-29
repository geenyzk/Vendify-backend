<?php

namespace App\Services\Electricity;

use App\Models\User;

class ElectricitySandboxAccess
{
    public function allowedFor(?User $user): bool
    {
        if (! $user || ! (bool) config('electricity.sandbox_enabled', false)) {
            return false;
        }

        $allowed = config('electricity.sandbox_allowed_emails', []);
        if (! is_array($allowed) || $allowed === []) {
            return false;
        }

        $email = strtolower(trim((string) $user->email));
        $normalized = array_map(
            fn ($candidate) => strtolower(trim((string) $candidate)),
            $allowed,
        );

        return $email !== '' && in_array($email, $normalized, true);
    }
}
