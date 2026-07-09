<?php

namespace App\Classes\Vendor\Providers;

use App\Classes\Vendor\VendorBase;
use App\Models\Vendor;
use App\Http\Controllers\AdminController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class SandboxService extends VendorBase
{

    public function __construct(Vendor $provider)
    {
        parent::__construct($provider);
        $this->isSandbox = true;
    }
    protected string $providerName = 'sandbox';

    public function sendRequest(string $service, array $payload): array
    {
        $simulatedStatus = $payload['simulate_status'] ?? 'success';
        // Cable purchases identify the customer by 'iuc', every other
        // supported service here by 'phone'/'meter_number' under the
        // 'phone' key already used below — fall back across whichever one
        // the real payload actually has.
        $account = $payload['phone'] ?? $payload['iuc'] ?? $payload['meter_number'] ?? null;

        return [
            'status' => $simulatedStatus === 'success' ? 'successful' : 'failed',
            'amount' => $payload['amount'],
            // No vendor-side markup simulated here — the real discount is
            // already computed server-side (Discount::getDiscountedAmount)
            // before this vendor layer is ever reached, same as every real
            // vendor's own 'data'/'electricity' cases (which hardcode 0.00).
            // A separate fake 2% here meant the sandbox never actually
            // charged what production would have for the same request.
            'discount_amount' => 0,
            'phone' => $account,
            'plan_type' => $payload['plan_type'] ?? $payload['network_type'] ?? null,
            'token' => $simulatedStatus === 'success' ? Str::random(12) : null,
            'reference' => 'SBX-' . strtoupper(Str::random(8)),
            'request-id' => $payload['tx_ref'],
            'message' => $simulatedStatus === 'success' ? 'Sandbox transaction successful' : 'Sandbox transaction failed due to simulation',
        ];

    }

    public function checkBalance(): string
    {
        return "0";
    }

    public function verifyTransaction(string $tx_ref): array
    {
        return [
            'status' => 'success',
            'message' => 'Sandbox verification successful',
            'tx_ref' => $tx_ref,
        ];
    }

    public function formatPayload(string $service, array $payload): array
    {
        return $payload;
    }

    protected function getSupportedServices(): array
    {
        // Was missing 'cable' — USE_SANDBOX=true routes every vendor call
        // through this class regardless of which real vendor is configured,
        // so cable purchases would have thrown "No formatter for [cable]"
        // (see formatResponse below) the instant sandbox mode was enabled.
        return ['airtime', 'data', 'cable', 'electricity'];
    }

    protected function formatResponse(string $service, array $response): array
    {
        $status = $response['status'] === 'successful' ? 'success' : 'fail';

        $base = [
            'provider' => $this->providerName,
            'status' => $status,
            'transaction_reference' => $response['tx_ref'] ?? $response['request-id'],
            'payment_reference' => $response['reference'] ?? null,
            'response_message' => $status === 'success' ? 'Success' : 'Failed',
            'completed_at' => now(),
            // $response is the raw vendor reply merged with the original
            // $validated payload — pass through whatever
            // VTUServicesController computed (e.g. the Bill Plan fee for
            // electricity) instead of always zeroing it.
            'service_fee' => (float) ($response['service_fee'] ?? 0),
            'platform' => 'sandbox',
        ];

        $common = [
            'account_or_phone' => $response['phone'],
            'amount' => $response['amount'],
            'discount_amount' => $response['discount_amount'],
            'quantity' => 1.00,
            'status' => $status,
            'receiver' => $response['phone'],
            'plan_type' => $response['plan_type'],
            'token' => $response['token'] ?? null,
        ];

        $types = [
            'airtime' => ['transaction_type' => 'airtime_recharge'],
            'data' => ['transaction_type' => 'data_subscription'],
            'cable' => ['transaction_type' => 'cable_subscription'],
            // Was 'electricity_payment' — not a real value in the
            // transactions.transaction_type enum ('electric_bill' is), so
            // every simulated electricity purchase failed at the database
            // insert with a truncation error.
            'electricity' => ['transaction_type' => 'electric_bill'],
        ];

        if (!isset($types[$service])) {
            throw new \InvalidArgumentException("No formatter for [$service]");
        }

        return array_merge($base, $types[$service], $common);
    }

    function login(): mixed
    {
        return [];
    }

    function verifyUser(string $service, string $identifier, array $payload): JsonResponse
    {
        return $this->success([]);
    }

    /**
     * Stub method to fulfill base class contract
     */
    protected function getAuthHeaders(): array
    {
        return [
            'Authorization' => 'Bearer sandbox-token',
            'Content-Type' => 'application/json',
        ];
    }

    /**
     * Stub method for pinging sandbox
     */
    protected function pingEndpoint(): string
    {
        return 'https://sandbox.vendor.local/ping';
    }

    /**
     * Endpoint simulation for different services
     */
    protected function endpoint(string $service): string
    {
        return match ($service) {
            'airtime' => '/airtime',
            'data' => '/data',
            'cable' => '/cable',
            'electricity' => '/electricity',
            default => throw new \InvalidArgumentException("No endpoint mapped for service [$service]"),
        };
    }

    /**
     * Combines base URL + endpoint
     */
    protected function buildEndpoint(string $service): string
    {
        return $this->baseUrl() . $this->endpoint($service);
    }

    /**
     * Dummy base URL
     */
    protected function baseUrl(): string
    {
        return 'https://sandbox.vendor.local/api';
    }

    function callback(Request $request): array
    {
        return [];
    }

   protected function getPlans(?array $payload = null): JsonResponse
    {
        // universalGet() is an instance method — calling it via
        // `AdminController::universalGet(...)` throws "Non-static method
        // ... cannot be called statically" the moment this actually runs.
        return (new AdminController())->universalGet($payload['request'], $payload['table']);
    }
}
