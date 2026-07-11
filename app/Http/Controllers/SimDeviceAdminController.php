<?php

namespace App\Http\Controllers;

use App\Models\Sim;
use App\Models\SimDevice;
use App\Models\SimVendJob;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Admin surface for the SIM vending fleet — deliberately its OWN section,
 * separate from the external-provider ("APIs") management: these are the
 * platform's physical SIMs and agent phones, not a configurable vendor.
 * Registration mirrors the child-instance flow: generate a one-time code,
 * type it into the agent app, the device self-registers.
 */
class SimDeviceAdminController extends Controller
{
    /** Fleet dashboard: devices with their SIMs, plus job/queue health. */
    public function overview(): JsonResponse
    {
        $devices = SimDevice::with('sims')->orderBy('name')->get()->map(fn (SimDevice $d) => [
            'id' => $d->id,
            'name' => $d->name,
            'slug' => $d->slug,
            'status' => $d->status,
            'online' => $d->isOnline(),
            'last_seen_at' => $d->last_seen_at,
            'app_version' => $d->app_version,
            'registered_at' => $d->registered_at,
            'registration_code' => $d->registered_at ? null : $d->registration_code,
            'registration_code_expires_at' => $d->registered_at ? null : $d->registration_code_expires_at,
            'sims' => $d->sims->map(fn (Sim $s) => [
                'id' => $s->id,
                'slot_index' => $s->slot_index,
                'network' => $s->network,
                'phone_number' => $s->phone_number,
                'supports_airtime' => $s->supports_airtime,
                'supports_data' => $s->supports_data,
                'airtime_balance' => $s->airtime_balance,
                'data_balance_mb' => $s->data_balance_mb,
                'airtime_low_threshold' => $s->airtime_low_threshold,
                'data_low_threshold_mb' => $s->data_low_threshold_mb,
                // Never the PIN itself — the UI only needs to know whether
                // one is configured (vends fail without it).
                'has_pin' => (bool) $s->transfer_pin,
                'balance_ussd' => $s->balance_ussd,
                'enabled' => $s->enabled,
                'balance_reported_at' => $s->balance_reported_at,
                'low' => ($s->supports_airtime && $s->airtime_balance < $s->airtime_low_threshold)
                    || ($s->supports_data && $s->data_balance_mb < $s->data_low_threshold_mb),
            ])->values(),
        ]);

        $jobCounts = SimVendJob::query()
            ->select('status', DB::raw('count(*) as total'))
            ->groupBy('status')
            ->pluck('total', 'status');

        $recentJobs = SimVendJob::with('device:id,name')
            ->orderByDesc('id')
            ->limit(25)
            ->get()
            ->map(fn (SimVendJob $j) => [
                'id' => $j->id,
                'reference' => $j->transaction_reference,
                'service' => $j->service,
                'network' => $j->network,
                'phone' => $j->phone,
                'amount' => $j->amount,
                'status' => $j->status,
                'attempts' => $j->attempts,
                'device' => $j->device?->name,
                'failure_reason' => $j->failure_reason,
                'created_at' => $j->created_at,
                'acked_at' => $j->acked_at,
            ]);

        return $this->success([
            'enabled' => (bool) config('simvending.enabled'),
            'devices' => $devices,
            'job_counts' => [
                'pending' => (int) ($jobCounts['pending'] ?? 0),
                'claimed' => (int) ($jobCounts['claimed'] ?? 0),
                'success' => (int) ($jobCounts['success'] ?? 0),
                'failed' => (int) ($jobCounts['failed'] ?? 0),
            ],
            'recent_jobs' => $recentJobs,
        ]);
    }

    // Same trust bootstrap as affiliates: name in, one-time code out. The
    // device exchanges the code for its slug + secret on first connect
    // (SimDeviceRegistrationController::register).
    public function generateCode(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
        ]);

        $device = SimDevice::create([
            'name' => $validated['name'],
            'status' => 'active',
            'registration_code' => Str::upper(Str::random(10)),
            'registration_code_expires_at' => now()->addDay(),
        ]);

        return $this->success([
            'id' => $device->id,
            'name' => $device->name,
            'registration_code' => $device->registration_code,
            'expires_at' => $device->registration_code_expires_at,
        ], 'Registration code generated');
    }

    /**
     * Rotate a device's secret. The old signature stops verifying instantly;
     * the new secret is returned once so it can be typed into the agent.
     */
    public function regenerateSecret(Request $request, string $id): JsonResponse
    {
        $device = SimDevice::find($id);
        if (!$device) {
            return $this->fail([], 'Device not found', 404);
        }

        $device->forceFill(['shared_secret' => Str::random(64)])->save();

        return $this->success(['secret' => $device->shared_secret], 'Secret regenerated');
    }

    /** Pause/resume a device (paused devices fail HMAC auth and get no jobs). */
    public function updateDevice(Request $request, string $id): JsonResponse
    {
        $device = SimDevice::find($id);
        if (!$device) {
            return $this->fail([], 'Device not found', 404);
        }

        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'status' => 'sometimes|in:active,paused,revoked',
        ]);

        $device->update($validated);

        return $this->success($device, 'Device updated');
    }

    public function deleteDevice(Request $request, string $id): JsonResponse
    {
        $device = SimDevice::find($id);
        if (!$device) {
            return $this->fail([], 'Device not found', 404);
        }

        $device->delete(); // sims cascade

        return $this->success([], 'Device deleted');
    }

    /**
     * Admin-defined SIM: the vend config (network, transfer PIN, balance
     * USSD) lives HERE in the database, not in the hub's local files — the
     * hub pulls it via GET /api/sim/{slug}/config. Devices may also
     * auto-create sims via registration/heartbeat; this endpoint is for
     * defining them up front or adding one to a live device.
     */
    public function createSim(Request $request, string $id): JsonResponse
    {
        $device = SimDevice::find($id);
        if (!$device) {
            return $this->fail([], 'Device not found', 404);
        }

        $validated = $request->validate([
            'slot_index' => 'required|integer|min:0|max:255',
            'network' => 'required|string|max:50',
            'phone_number' => 'nullable|string|max:30',
            'transfer_pin' => 'nullable|string|max:20',
            'balance_ussd' => 'nullable|string|max:30',
            'supports_airtime' => 'sometimes|boolean',
            'supports_data' => 'sometimes|boolean',
        ]);
        $validated['network'] = strtolower(trim($validated['network']));

        $sim = $device->sims()->updateOrCreate(
            ['slot_index' => $validated['slot_index']],
            $validated,
        );

        return $this->success($sim, 'SIM saved');
    }

    /**
     * Both route params declared in order — Laravel binds POSITIONALLY, so a
     * signature missing $id would hand the device id in as $simId (the bug
     * that once broke every child directive ack).
     */
    public function updateSim(Request $request, string $id, string $simId): JsonResponse
    {
        $sim = Sim::where('sim_device_id', $id)->find((int) $simId);
        if (!$sim) {
            return $this->fail([], 'SIM not found', 404);
        }

        $validated = $request->validate([
            'network' => 'sometimes|string|max:50',
            'phone_number' => 'sometimes|nullable|string|max:30',
            'supports_airtime' => 'sometimes|boolean',
            'supports_data' => 'sometimes|boolean',
            'airtime_low_threshold' => 'sometimes|numeric|min:0',
            'data_low_threshold_mb' => 'sometimes|numeric|min:0',
            'transfer_pin' => 'sometimes|nullable|string|max:20',
            'balance_ussd' => 'sometimes|nullable|string|max:30',
            'enabled' => 'sometimes|boolean',
            'notes' => 'sometimes|nullable|string|max:255',
        ]);

        if (isset($validated['network'])) {
            $validated['network'] = strtolower(trim($validated['network']));
        }

        $sim->update($validated);

        return $this->success($sim, 'SIM updated');
    }
}
