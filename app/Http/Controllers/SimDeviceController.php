<?php

namespace App\Http\Controllers;

use App\Classes\AdminNotifier;
use App\Models\Sim;
use App\Models\SimDevice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Heartbeat half of the SIM-device channel: the agent checks in on its poll
 * cadence with the live state of every SIM it hosts. The signed request
 * itself already bumped last_seen_at (SimDeviceAuthenticator), so this is
 * about stock: reported balances drive routing eligibility
 * (Sim::scopeEligible) and low-stock admin alerts.
 */
class SimDeviceController extends Controller
{
    /**
     * The device's own vend configuration, admin-managed in the database
     * (Admin → APIs → SIM Vending) rather than hand-edited on the hub. The
     * hub pulls this on startup and re-pulls periodically, so PIN/network
     * changes apply without touching the hub's filesystem. transfer_pin is
     * decrypted here — this endpoint is HMAC-signed and only ever answers
     * the device that owns the SIMs.
     */
    public function config(Request $request, string $slug): JsonResponse
    {
        /** @var SimDevice $device */
        $device = $request->attributes->get('simDevice');

        return $this->success([
            'sims' => $device->sims->map(fn (Sim $sim) => [
                'slot_index' => $sim->slot_index,
                'network' => $sim->network,
                'phone_number' => $sim->phone_number,
                'supports_airtime' => $sim->supports_airtime,
                'supports_data' => $sim->supports_data,
                'enabled' => $sim->enabled,
                'transfer_pin' => $sim->transfer_pin,
                'balance_ussd' => $sim->balance_ussd,
            ])->values(),
            'config' => [
                'poll_interval' => (int) config('simvending.poll_interval'),
                'lease_seconds' => (int) config('simvending.lease_seconds'),
            ],
        ]);
    }

    public function heartbeat(Request $request, string $slug): JsonResponse
    {
        /** @var SimDevice $device */
        $device = $request->attributes->get('simDevice');

        $validated = $request->validate([
            'app_version' => ['nullable', 'string', 'max:50'],
            'sims' => ['array'],
            'sims.*.slot_index' => ['required', 'integer', 'min:0', 'max:255'],
            'sims.*.network' => ['required', 'string', 'max:50'],
            'sims.*.phone_number' => ['nullable', 'string', 'max:30'],
            'sims.*.airtime_balance' => ['nullable', 'numeric', 'min:0'],
            'sims.*.data_balance_mb' => ['nullable', 'numeric', 'min:0'],
        ]);

        if (!empty($validated['app_version'])) {
            $device->forceFill(['app_version' => $validated['app_version']])->saveQuietly();
        }

        foreach ($validated['sims'] ?? [] as $reported) {
            $sim = $device->sims()->updateOrCreate(
                ['slot_index' => $reported['slot_index']],
                array_filter([
                    'network' => strtolower(trim($reported['network'])),
                    'phone_number' => $reported['phone_number'] ?? null,
                    'airtime_balance' => $reported['airtime_balance'] ?? null,
                    'data_balance_mb' => $reported['data_balance_mb'] ?? null,
                ], fn ($v) => $v !== null) + ['balance_reported_at' => now()],
            );

            $this->maybeAlertLowBalance($sim);
        }

        return $this->success([
            'config' => [
                'poll_interval' => (int) config('simvending.poll_interval'),
                'lease_seconds' => (int) config('simvending.lease_seconds'),
            ],
        ]);
    }

    /**
     * One alert per SIM per configured interval (Cache::add is atomic — the
     * same lock pattern CheckVendorBalances uses) so a depleted SIM doesn't
     * page the admin on every 15-second poll.
     */
    private function maybeAlertLowBalance(Sim $sim): void
    {
        $low = ($sim->supports_airtime && $sim->airtime_balance < $sim->airtime_low_threshold)
            || ($sim->supports_data && $sim->data_balance_mb < $sim->data_low_threshold_mb);

        if (!$low || !$sim->enabled) {
            return;
        }

        $interval = (int) config('simvending.low_balance_alert_interval');
        if (Cache::add("sim_low_balance_alert_{$sim->id}", true, $interval)) {
            AdminNotifier::notifySimLowBalance($sim);
        }
    }
}
