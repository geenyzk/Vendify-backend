<?php

namespace App\Classes\Vendor\Providers;

use App\Classes\Vendor\VendorBase;
use App\Models\DataPlan;
use App\Models\BillPlan;
use App\Models\CablePlan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class CheapDataHub extends VendorBase
{
    protected string $providerName = 'cheapdatahub';

    protected function baseUrl(): string
    {
        $base = rtrim((string) ($this->provider->base_url ?: config('services.cheapdatahub.base_url')), '/');
        if (! preg_match('#/api/v1/resellers$#', $base)) {
            throw new \InvalidArgumentException('CheapDataHub base URL must end with /api/v1/resellers.');
        }
        return $base;
    }

    private function apiKey(): string
    {
        // The provider record is the admin-managed credential source. Env is
        // only a deployment fallback; it must not override a replaced key.
        return (string) ($this->provider->api_key ?: config('services.cheapdatahub.api_key'));
    }

    protected function endpoint(string $service): string
    {
        return match ($service) {
            'airtime' => '/airtime/purchase/',
            'data' => '/data/purchase/',
            'electricity' => '/electricity/purchase/',
            'cable' => '/cable/purchase/',
            default => throw new \InvalidArgumentException("CheapDataHub does not support service [{$service}]."),
        };
    }

    protected function pingEndpoint(): string
    {
        return $this->baseUrl().'/wallet/balance/';
    }

    protected function getSupportedServices(): array
    {
        return ['airtime', 'data', 'cable', 'electricity'];
    }

    protected function getAuthHeaders(): array
    {
        return [
            'Authorization' => 'Bearer '.$this->apiKey(),
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        ];
    }

    public function login(): array
    {
        try {
            $response = Http::connectTimeout(5)->timeout(15)
                ->withHeaders($this->getAuthHeaders())->get($this->pingEndpoint());
            return ['status' => $response->successful() ? 'success' : 'fail'];
        } catch (\Throwable) {
            return ['status' => 'fail'];
        }
    }

    public function checkBalance(): string
    {
        try {
            $response = Http::connectTimeout(5)->timeout(15)
                ->withHeaders($this->getAuthHeaders())->get($this->pingEndpoint());
        } catch (ConnectionException $e) {
            throw new \RuntimeException('CheapDataHub balance request is temporarily unavailable.', 0, $e);
        }

        if (! $response->successful()) {
            throw new \RuntimeException($this->errorMessage($response->status()));
        }

        return (string) $this->normalizeAmount($response->json('data.balance', 0));
    }

    public function sendRequest(string $service, array $payload): array
    {
        Log::info('CheapDataHub purchase requested', [
            'service' => $service,
            'provider_id' => $this->provider->id,
            'data_plan_id' => $payload['_data_plan_id'] ?? null,
            'bundle_id' => $payload['bundle_id'] ?? null,
        ]);

        unset($payload['_data_plan_id']);

        try {
            $response = Http::connectTimeout(8)->timeout(45)
                ->withHeaders($this->getAuthHeaders())
                ->post($this->baseUrl().$this->endpoint($service), $payload);
            $body = $response->json();
            $body = is_array($body) ? $body : [];
            if (is_array($body['data'] ?? null)) {
                $body = array_merge($body, $body['data']);
                unset($body['data']);
            }
            $body['_http_status'] = $response->status();
        } catch (ConnectionException $e) {
            Log::warning('CheapDataHub purchase network failure', [
                'service' => $service,
                'provider_id' => $this->provider->id,
                'bundle_id' => $payload['bundle_id'] ?? null,
                'error' => 'network_error',
            ]);
            return [
                '_transport_ambiguous' => true,
                'message' => 'CheapDataHub request outcome is unknown and requires provider review.',
            ];
        }

        Log::log($response->successful() ? 'info' : 'warning', 'CheapDataHub purchase result', [
            'service' => $service,
            'provider_id' => $this->provider->id,
            'bundle_id' => $payload['bundle_id'] ?? null,
            'provider_reference' => $body['reference'] ?? null,
            'success' => $response->successful() && $this->truthy($body['status'] ?? false),
            'error' => $response->successful() ? null : $this->errorCode($response->status()),
        ]);

        return $body;
    }

    public function canServePlan(string $service, $planId): bool
    {
        if (in_array($service, ['airtime', 'electricity'], true)) {
            return true;
        }
        if (! in_array($service, ['data', 'cable'], true) || ! $planId) {
            return false;
        }

        $model = $service === 'cable' ? CablePlan::class : DataPlan::class;

        return DB::table('providerables')
            ->where('providerable_id', $planId)
            ->where('providerable_type', $model)
            ->where('provider_id', $this->provider->id)
            ->where('provider_enabled', true)
            ->where('provider_available', true)
            ->where(function ($query) {
                $query->whereNotNull('external_plan_id')->where('external_plan_id', '<>', '')
                    ->orWhere(function ($legacy) {
                        $legacy->whereNotNull('server_id')->where('server_id', '<>', '');
                    });
            })->exists();
    }

    public function formatPayload(string $service, array $payload): array
    {
        if ($service === 'airtime') {
            $network = strtolower(trim((string) ($payload['network'] ?? '')));
            $cheapDataHubNetworkProviderId = config("services.cheapdatahub.airtime_network_ids.{$network}");
            if (! is_numeric($cheapDataHubNetworkProviderId)) {
                throw new \InvalidArgumentException("CheapDataHub airtime network mapping is not configured for [{$network}].");
            }

            return [
                // This is CheapDataHub's network identifier, never Vendify's
                // internal providers.id value.
                'provider_id' => (int) $cheapDataHubNetworkProviderId,
                'phone_number' => (string) $payload['phone'],
                'amount' => (int) $payload['amount'],
            ];
        }

        if ($service === 'electricity') {
            $billPlan = BillPlan::where('disco', (string) ($payload['disco'] ?? ''))
                ->where('active', true)
                ->first();
            $mapping = $billPlan ? DB::table('providerables')
                ->where('providerable_id', $billPlan->id)
                ->where('providerable_type', BillPlan::class)
                ->where('provider_id', $this->provider->id)
                ->first() : null;
            $discoId = $mapping->external_plan_id ?? $mapping->server_id ?? null;

            if (! is_numeric($discoId)) {
                throw new \InvalidArgumentException('This electricity disco has no numeric CheapDataHub disco ID mapping.');
            }

            return [
                'disco_id' => (int) $discoId,
                'meter_number' => (string) $payload['meter_number'],
                'amount' => (int) $payload['amount'],
                'meter_type' => strtolower((string) $payload['meter_type']),
                'phone' => (string) ($payload['phone'] ?? Auth::user()?->phone ?? ''),
            ];
        }

        if ($service === 'cable') {
            $plan = CablePlan::find($payload['cable_plan'] ?? null);
            if (! $plan) {
                throw new \InvalidArgumentException('Cable plan not found.');
            }
            $mapping = DB::table('providerables')
                ->where('providerable_id', $plan->id)
                ->where('providerable_type', CablePlan::class)
                ->where('provider_id', $this->provider->id)
                ->where('provider_enabled', true)
                ->where('provider_available', true)
                ->first();
            $planId = $mapping->external_plan_id ?? $mapping->server_id ?? null;
            if (! is_numeric($planId)) {
                throw new \InvalidArgumentException('This cable plan has no numeric CheapDataHub plan mapping.');
            }

            return [
                'plan_id' => (int) $planId,
                'cardnumber' => (string) $payload['iuc'],
                'phone' => (string) ($payload['phone'] ?? Auth::user()?->phone ?? ''),
            ];
        }

        $plan = DataPlan::find($payload['data_plan'] ?? null);
        if (! $plan) {
            throw new \InvalidArgumentException('Data plan not found.');
        }

        $mapping = DB::table('providerables')
            ->where('providerable_id', $plan->id)
            ->where('providerable_type', DataPlan::class)
            ->where('provider_id', $this->provider->id)
            ->first();
        $bundleId = $mapping->external_plan_id ?? $mapping->server_id ?? null;

        if ($bundleId === null || $bundleId === '' || $bundleId === '0') {
            throw new \InvalidArgumentException("Data plan [{$plan->id}] has no CheapDataHub mapping.");
        }

        return [
            'bundle_id' => is_numeric($bundleId) ? (int) $bundleId : $bundleId,
            'phone_number' => $payload['phone'],
            '_data_plan_id' => $plan->id,
        ];
    }

    protected function formatResponse(string $service, array $response): array
    {
        $httpStatus = (int) ($response['_http_status'] ?? 0);
        $ambiguous = (bool) ($response['_transport_ambiguous'] ?? false);
        $success = ! $ambiguous && $httpStatus >= 200 && $httpStatus < 300 && $this->truthy($response['status'] ?? false);
        $explicitFailure = array_key_exists('status', $response)
            && in_array(strtolower((string) $response['status']), ['false', 'fail', 'failed', 'failure'], true);
        $message = $success
            ? ($response['message'] ?? ($service === 'electricity' ? 'Electricity purchase successful' : 'Data purchase successful'))
            : $this->errorMessage($httpStatus, $response['message'] ?? null);

        return [
            'provider' => $this->providerName,
            'transaction_type' => match ($service) {
                'airtime' => 'airtime_recharge',
                'cable' => 'cable_subscription',
                'electricity' => 'electric_bill',
                default => 'data_subscription',
            },
            'status' => $ambiguous ? 'pending' : ($success ? 'success' : 'fail'),
            'transaction_reference' => $response['tx_ref'] ?? null,
            'payment_reference' => $response['reference'] ?? $response['transaction_id'] ?? null,
            'response_message' => $message,
            'account_or_phone' => $service === 'electricity' ? ($response['meter_number'] ?? null) : ($service === 'cable' ? ($response['iuc'] ?? null) : ($response['phone'] ?? null)),
            'receiver' => $service === 'electricity' ? ($response['meter_number'] ?? null) : ($service === 'cable' ? ($response['iuc'] ?? null) : ($response['phone'] ?? null)),
            'amount' => $response['amount'] ?? 0,
            'discount_amount' => $response['discount_amount'] ?? 0,
            'plan_type' => match ($service) {
                'airtime' => $response['network_type'] ?? 'VTU',
                'cable' => $response['subscription_type'] ?? 'change',
                'electricity' => $response['meter_type'] ?? null,
                default => $response['plan_type'] ?? 'DATA',
            },
            'token' => $service === 'electricity' && is_scalar($response['token'] ?? null)
                ? (string) $response['token']
                : null,
            // Only definitive pre-fulfilment rejections are safe to retry.
            'safe_to_retry' => ! $ambiguous && ! $success && (
                in_array($httpStatus, [401, 402, 422], true)
                || ($explicitFailure && $httpStatus < 500 && $httpStatus !== 409)
            ),
            'provider_status' => $ambiguous ? 'transport-ambiguous' : (string) ($response['status'] ?? $httpStatus),
            'raw_payload' => [
                'provider_status' => $ambiguous ? 'transport-ambiguous' : ($response['status'] ?? null),
                'provider_reference' => $response['reference'] ?? $response['transaction_id'] ?? null,
                'meter_number' => $service === 'electricity' ? ($response['meter_number'] ?? null) : null,
                'meter_type' => $service === 'electricity' ? ($response['meter_type'] ?? null) : null,
                'customer_name' => $service === 'electricity' ? ($response['customer_name'] ?? null) : null,
                'distribution_company' => $service === 'electricity' ? ($response['disco'] ?? null) : null,
                'units' => $service === 'electricity' ? ($response['units'] ?? null) : null,
            ],
            'completed_at' => $success ? now() : null,
        ];
    }

    private function truthy(mixed $value): bool
    {
        return $value === true || $value === 1 || in_array(strtolower((string) $value), ['true', 'success', 'successful'], true);
    }

    private function errorCode(int $status): string
    {
        return match ($status) {
            401 => 'unauthorized', 402 => 'insufficient_vendor_balance',
            409 => 'duplicate_request', 422 => 'validation_error',
            default => $status >= 500 ? 'provider_error' : 'request_failed',
        };
    }

    private function errorMessage(int $status, ?string $providerMessage = null): string
    {
        return match ($status) {
            401 => 'CheapDataHub authentication failed.',
            402 => 'CheapDataHub has insufficient vendor balance.',
            409 => 'CheapDataHub rejected a duplicate request.',
            422 => 'CheapDataHub rejected the purchase details.',
            500, 502, 503, 504 => 'CheapDataHub is temporarily unavailable.',
            default => $providerMessage ?: 'CheapDataHub purchase failed.',
        };
    }

    public function verifyTransaction(string $tx_ref): array { return []; }

    public function verifyUser(string $service, string $identifier, array $payload): JsonResponse
    {
        return $this->fail([], 'Verification is not supported by CheapDataHub.', 422);
    }

    protected function getPlans(?array $payload = null): array { return []; }
    protected function callback(Request $request): array { return []; }
}
