<?php

namespace App\Classes\Vendor\Providers;

use App\Classes\Vendor\VendorBase;
use App\Http\Controllers\AdminController;
use App\Models\DataPlan;
use App\Models\Sim;
use App\Models\SimDevice;
use App\Models\SimVendJob;
use App\Models\Vendor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * SIM Vending — fulfils airtime/data off the platform's own physical SIMs
 * hosted in Android agent devices, with no external provider.
 *
 * Unlike every other vendor here, sendRequest() makes no HTTP call: it
 * writes a SimVendJob to the outbox and reports 'pending', so process()
 * reserves funds, records a pending transaction and returns 202 exactly as
 * it does for Ogdams' queued vends. A device then claims the job over the
 * signed /api/sim channel, executes the USSD transfer / data gift, and its
 * ack settles the transaction through settleCallback() (success keeps the
 * funds + pays rewards; failure refunds). See SIM_VENDING_PROTOCOL.md.
 *
 * Whether this vendor is even offered a purchase is decided earlier by
 * canServe() (called from VTUServiceFactory::resolveProvider) — when no
 * online device has a matching SIM with enough stock, resolution falls
 * through to the next configured provider and no funds are ever held here.
 */
class SimVending extends VendorBase
{
    protected string $providerName = 'simvend';

    /**
     * The failover gate: can a physical SIM fulfil this vend right now?
     * $need is the vend amount for airtime (naira) or resolved from the
     * plan's size for data (MB); SIM stock must cover it plus the airtime
     * reserve headroom, and the host device must be online.
     */
    public static function canServe(string $service, ?string $network, ?float $amount = null, $planId = null): bool
    {
        if (!config('simvending.enabled') || !$network || !in_array($service, ['airtime', 'data'], true)) {
            return false;
        }

        if (!Vendor::where('sub_category', 'simvend')->where('active', true)->exists()) {
            return false;
        }

        $need = null;
        if ($service === 'airtime' && $amount !== null) {
            $need = $amount + (float) config('simvending.airtime_reserve');
        } elseif ($service === 'data') {
            $need = $planId ? self::planSizeMb($planId) : null;
        }

        return Sim::eligible($service, $network, $need)->exists();
    }

    /** The plan's advertised volume in MB, or null when it can't be parsed. */
    public static function planSizeMb($planId): ?float
    {
        $plan = DataPlan::find($planId);
        if (!$plan) {
            return null;
        }

        if (preg_match('/([\d.]+)\s*(MB|GB)/i', (string) $plan->plan, $m)) {
            $value = (float) $m[1];
            return strtoupper($m[2]) === 'GB' ? round($value * 1024, 2) : $value;
        }

        return null;
    }

    public function formatPayload(string $service, array $payload): array
    {
        switch ($service) {
            case 'airtime':
                return [
                    'reference' => $payload['tx_ref'],
                    'network' => strtolower((string) $payload['network']),
                    'phone' => $payload['phone'],
                    'amount' => (float) $payload['amount'],
                    'type' => $payload['network_type'] ?? 'vtu',
                ];

            case 'data':
                $dataPlan = DataPlan::find($payload['data_plan']);
                if (!$dataPlan) {
                    throw new \InvalidArgumentException("Data plan [{$payload['data_plan']}] not found");
                }
                // Optional per-plan vend hint (USSD/share code) an admin can
                // store on the providerables pivot's server_id — same slot
                // other vendors keep their remote plan IDs in. The agent
                // falls back to its own network+size mapping when absent.
                $vendCode = $this->configuredPlanId($dataPlan);
                return [
                    'reference' => $payload['tx_ref'],
                    'network' => strtolower((string) $payload['network']),
                    'phone' => $payload['phone'],
                    'amount' => (float) $payload['amount'],
                    'data_plan_id' => $dataPlan->id,
                    'plan_snapshot' => [
                        'name' => (string) $dataPlan->plan,
                        'size_mb' => self::planSizeMb($dataPlan->id),
                        'validity' => $dataPlan->validity,
                        'plan_type' => $dataPlan->plan_type,
                        'vend_code' => $vendCode,
                    ],
                ];

            default:
                throw new \InvalidArgumentException("Unknown service [$service] for SIM vending");
        }
    }

    /**
     * No HTTP — enqueue the job for a device to claim. Runs after funds are
     * reserved; if the insert throws, process()'s catch-all refunds.
     */
    public function sendRequest(string $service, array $payload): array
    {
        SimVendJob::create([
            'transaction_reference' => $payload['reference'],
            'user_id' => Auth::id(),
            'service' => $service,
            'network' => $payload['network'],
            'phone' => $payload['phone'],
            'amount' => $payload['amount'],
            'data_plan_id' => $payload['data_plan_id'] ?? null,
            'plan_snapshot' => $payload['plan_snapshot'] ?? null,
            'status' => SimVendJob::STATUS_PENDING,
        ]);

        return [
            'status' => 'pending',
            'ref' => $payload['reference'],
            'msg' => 'Queued for SIM delivery',
        ];
    }

    protected function formatResponse(string $service, array $response): array
    {
        $transactionTypes = [
            'airtime' => 'airtime_recharge',
            'data' => 'data_subscription',
        ];

        if (!isset($transactionTypes[$service])) {
            throw new \InvalidArgumentException("No response formatter defined for service [$service]");
        }

        return [
            'provider' => $this->providerName,
            'transaction_type' => $transactionTypes[$service],
            // Never final at purchase time — the device's ack (or the expiry
            // sweeper) settles it via settleCallback().
            'status' => 'pending',
            'transaction_reference' => $response['ref'] ?? $response['tx_ref'] ?? null,
            'payment_reference' => $response['ref'] ?? null,
            'response_message' => $response['msg'] ?? null,
            'completed_at' => null,
            'discount_amount' => 0.00,
            'service_fee' => 0.00,
            'platform' => 'sim',
            'account_or_phone' => $response['phone'] ?? null,
            'amount' => $response['amount'] ?? 0.00,
            'quantity' => 1.00,
            'receiver' => $response['phone'] ?? null,
            'plan_type' => $response['plan_type'] ?? null,
            'token' => null,
        ];
    }

    public function checkBalance(): string
    {
        return (string) Sim::where('enabled', true)->sum('airtime_balance');
    }

    /**
     * No credentials to exchange — "logged in" means at least one device is
     * online, so the admin connection indicator reflects fleet health.
     */
    public function login(): array
    {
        return SimDevice::online()->exists()
            ? ['status' => 'success']
            : ['status' => 'fail'];
    }

    public function verifyTransaction(string $tx_ref): array
    {
        $job = SimVendJob::where('transaction_reference', $tx_ref)->first();

        return $job ? $job->toArray() : [];
    }

    protected function getAuthHeaders(): array
    {
        return [];
    }

    public function verifyUser(string $service, string $identifier, array $payload): JsonResponse
    {
        // Airtime/data need no customer-identity lookup (unlike cable/
        // electricity), and there is no remote API to ask anyway.
        return $this->fail([], "Verification not supported for service: $service");
    }

    protected function getSupportedServices(): array
    {
        return ['airtime', 'data'];
    }

    protected function getPlans(?array $payload = null): JsonResponse
    {
        return (new AdminController())->universalGet($payload['request'], $payload['table']);
    }

    /**
     * Kept for interface completeness (the generic /api/webhook route could
     * still target this vendor by identifier) — the ack endpoint calls
     * settleCallback() directly instead.
     */
    protected function callback(Request $request): array
    {
        return [
            'status' => $request->input('status'),
            'tx_ref' => $request->input('tx_ref'),
            'response_message' => $request->input('note'),
        ];
    }

    protected function pingEndpoint(): string
    {
        return '';
    }

    protected function endpoint(string $service): string
    {
        return '';
    }
}
