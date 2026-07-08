<?php

namespace App\Http\Controllers;

use App\Models\ChildInstance;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Self-registration: the admin generates a short-lived, single-use code
 * (AdminController::generateChildRegistrationCode — just needs a name),
 * hands it to whoever is setting up the child app, and the child calls
 * register() below exactly once to receive its real slug + shared_secret.
 * No admin form for base_url/status/etc. — the child reports its own
 * base_url via the push payload later if needed, and starts 'active' the
 * moment it registers.
 */
class ChildRegistrationController extends Controller
{
    public function register(Request $request): JsonResponse
    {
        $code = (string) $request->input('registration_code');
        if ($code === '') {
            return $this->fail([], 'registration_code is required', 422);
        }

        $instance = ChildInstance::where('registration_code', $code)
            ->whereNull('registered_at')
            ->first();

        if (!$instance) {
            return $this->fail([], 'Invalid or already-used registration code', 401);
        }

        if ($instance->registration_code_expires_at && $instance->registration_code_expires_at->isPast()) {
            return $this->fail([], 'Registration code expired — ask an admin to generate a new one', 401);
        }

        $instance->forceFill([
            'shared_secret' => Str::random(64),
            'status' => 'active',
            'registered_at' => now(),
            'registration_code' => null,
            'registration_code_expires_at' => null,
        ])->save();

        return $this->success([
            'slug' => $instance->slug,
            'shared_secret' => $instance->shared_secret,
        ], 'Registered');
    }
}
