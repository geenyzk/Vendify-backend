<?php

namespace App\Http\Controllers;

use App\Models\SimDevice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Self-registration for SIM-hosting agent devices, mirroring the child
 * channel's trust bootstrap: an admin generates a short-lived, single-use
 * code (SimDeviceAdminController::generateCode), it is typed into the agent
 * app, and the device calls register() exactly once to receive its slug +
 * shared_secret. The secret is shown only in this response — it is stored
 * encrypted and never serialized again.
 */
class SimDeviceRegistrationController extends Controller
{
    public function register(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'registration_code' => ['required', 'string'],
            'app_version' => ['nullable', 'string', 'max:50'],
            'sims' => ['array'],
            'sims.*.slot_index' => ['required', 'integer', 'min:0', 'max:255'],
            'sims.*.network' => ['required', 'string', 'max:50'],
            'sims.*.phone_number' => ['nullable', 'string', 'max:30'],
        ]);

        $device = SimDevice::where('registration_code', $validated['registration_code'])
            ->whereNull('registered_at')
            ->first();

        if (!$device) {
            return $this->fail([], 'Invalid or already-used registration code', 401);
        }

        if ($device->registration_code_expires_at && $device->registration_code_expires_at->isPast()) {
            return $this->fail([], 'Registration code expired — ask an admin to generate a new one', 401);
        }

        $device->forceFill([
            'shared_secret' => Str::random(64),
            'status' => 'active',
            'app_version' => $validated['app_version'] ?? null,
            'registered_at' => now(),
            'registration_code' => null,
            'registration_code_expires_at' => null,
        ])->save();

        foreach ($validated['sims'] ?? [] as $sim) {
            $device->sims()->updateOrCreate(
                ['slot_index' => $sim['slot_index']],
                [
                    'network' => strtolower(trim($sim['network'])),
                    'phone_number' => $sim['phone_number'] ?? null,
                ],
            );
        }

        return $this->success([
            'slug' => $device->slug,
            'shared_secret' => $device->shared_secret,
            'config' => [
                'poll_interval' => (int) config('simvending.poll_interval'),
                'lease_seconds' => (int) config('simvending.lease_seconds'),
            ],
        ], 'Registered');
    }
}
