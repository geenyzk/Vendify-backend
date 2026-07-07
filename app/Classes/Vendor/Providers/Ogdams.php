<?php

namespace App\Classes\Vendor\Providers;

use App\Classes\Vendor\VendorBase;
use App\Http\Controllers\AdminController;
use App\Models\DataPlan;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Ogdams Sim Hosting (SIMHOST) — https://simhosting.ogdams.ng
 * Docs: https://documenter.getpostman.com/view/9993865/UVyoVxpG
 *
 * Only airtime and data are supported by this vendor (confirmed directly,
 * not an oversight) — no cable/electricity/exam endpoints exist here.
 *
 * Auth is a single static Bearer token (Provider::api_key), no login/token
 * exchange. Every vend response carries both a boolean `status` and an
 * integer `code`, and the code has four meaningful values (per the docs):
 * 200 = done successfully, 424 = failed (refund immediately), 201 = queued/
 * awaiting attention, 202 = processing — the latter two are NOT final, they
 * mean "pending" (the real outcome arrives later via webhook).
 */
class Ogdams extends VendorBase
{
    protected string $providerName = 'ogdams';

    protected array $networkIDs = [
        'mtn' => 1,
        'airtel' => 2,
        'glo' => 3,
        '9mobile' => 4,
    ];

    public function sendRequest(string $service, array $payload): array
    {
        $response = Http::withHeaders($this->getAuthHeaders())
            ->post($this->buildEndpoint($service), $payload)
            ->json();

        // ->json() returns null on a non-JSON/empty body. VendorBase::process()
        // does array_merge($response['data'] ?? $response, ...) right after
        // this — array_merge(null, ...) would be a fatal TypeError.
        return $response ?? [];
    }

    public function checkBalance(): string
    {
        // No balance endpoint is documented for this vendor — be honest
        // about it rather than guess at a URL. The admin "connection" health
        // check below still works via the data-plans endpoint.
        return "0";
    }

    public function verifyTransaction(string $tx_ref): array
    {
        // No requery/verify-transaction endpoint is documented for this
        // vendor. Real status updates arrive via webhook (see callback()).
        return [];
    }

    /**
     * No login/token exchange — auth is a static Bearer token. This just
     * confirms the token/connectivity actually work, using the one GET
     * endpoint we know exists, for the admin connection-status check
     * (isHealthy()).
     */
    public function login(): array
    {
        try {
            $response = Http::withHeaders($this->getAuthHeaders())
                ->get($this->baseUrl() . '/api/v4/get/data/plans');

            return $response->successful() && $response->json('status') === true
                ? ['status' => 'success']
                : ['status' => 'fail'];
        } catch (\Throwable $th) {
            Log::error("Ogdams login/ping error: " . $th->getMessage());
            return ['status' => 'fail'];
        }
    }

    protected function getAuthHeaders(): array
    {
        return [
            'Authorization' => 'Bearer ' . $this->provider->api_key,
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        ];
    }

    protected function baseUrl(): string
    {
        return $this->provider->base_url;
    }

    protected function getSupportedServices(): array
    {
        return ['airtime', 'data'];
    }

    protected function pingEndpoint(): string
    {
        return $this->baseUrl() . '/api/v4/get/data/plans';
    }

    protected function endpoint(string $service): string
    {
        return match ($service) {
            'airtime' => '/api/v1/vend/airtime',
            'data' => '/api/v1/vend/data',
            default => throw new \InvalidArgumentException("No endpoint mapped for service [$service]"),
        };
    }

    protected function buildEndpoint(string $service): string
    {
        return $this->baseUrl() . $this->endpoint($service);
    }

    public function formatPayload(string $service, array $payload): array
    {
        switch ($service) {
            case 'airtime':
                return [
                    'networkId' => $this->networkIDs[$payload['network']],
                    'amount' => $payload['amount'],
                    'phoneNumber' => $payload['phone'],
                    'type' => $payload['network_type'] ?? 'vtu',
                    'reference' => $payload['tx_ref'],
                ];

            case 'data':
                $dataPlan = DataPlan::find($payload['data_plan']);
                if (!$dataPlan) {
                    throw new \InvalidArgumentException("Data plan [{$payload['data_plan']}] not found");
                }
                return [
                    'networkId' => $this->networkIDs[$payload['network']],
                    'planId' => $dataPlan->{str_replace(" ", "_", $this->provider->name)},
                    'phoneNumber' => $payload['phone'],
                    'reference' => $payload['tx_ref'],
                ];

            default:
                throw new \InvalidArgumentException("Unknown service [$service] for Ogdams");
        }
    }

    protected function formatResponse(string $service, array $response): array
    {
        $code = (int) ($response['code'] ?? 0);
        $status = match (true) {
            $code === 200 => 'success',
            $code === 424 => 'fail',
            // 201 (queued) / 202 (processing) aren't final — the real
            // outcome arrives later via the webhook (see callback()).
            default => 'pending',
        };

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
            'status' => $status,
            'transaction_reference' => $response['ref'] ?? $response['reference'] ?? null,
            'payment_reference' => $response['ref'] ?? null,
            'response_message' => $response['data']['msg'] ?? $response['msg'] ?? null,
            'completed_at' => now(),
            // The real discount is already computed server-side
            // (Discount::getDiscountedAmount) before this vendor call ever
            // happens — Ogdams doesn't echo back a separate discount/fee.
            'discount_amount' => 0.00,
            'service_fee' => (float) ($response['service_fee'] ?? 0),
            'platform' => 'api',
            'account_or_phone' => $response['phone'] ?? null,
            'amount' => $response['amount'] ?? 0.00,
            'quantity' => 1.00,
            'receiver' => $response['phone'] ?? null,
            'plan_type' => $response['plan_type'] ?? null,
            'token' => null,
        ];
    }

    public function verifyUser(string $service, string $identifier, array $payload): JsonResponse
    {
        // No customer-identity verification endpoint is documented (and
        // airtime/data don't need one the way cable/electricity do).
        return $this->fail([], "Verification not supported for service: $service");
    }

    protected function getPlans(?array $payload = null): JsonResponse
    {
        // universalGet() is an instance method — calling it via
        // `AdminController::universalGet(...)` throws "Non-static method
        // ... cannot be called statically".
        return (new AdminController())->universalGet($payload['request'], $payload['table']);
    }

    /**
     * Webhook payload shape (see class docblock):
     * { status, code, event: { type, data: { network, msg, reference } } }
     */
    protected function callback(Request $request): array
    {
        $code = (int) $request->input('code');
        $status = match (true) {
            $code === 200 => 'success',
            $code === 424 => 'fail',
            default => 'pending',
        };

        return [
            'status' => $status,
            'tx_ref' => $request->input('event.data.reference'),
            'response_message' => $request->input('event.data.msg'),
        ];
    }
}
