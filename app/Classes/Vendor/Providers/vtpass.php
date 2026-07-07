<?php

namespace App\Classes\Vendor\Providers;

use App\Classes\Vendor\VendorBase;
use App\Http\Controllers\AdminController;
use App\Models\CablePlan;
use App\Models\DataPlan;
use App\Models\DiscoProviderId;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class Vtpass extends VendorBase
{
    protected string $providerName = 'vtpass';

    function sendRequest(string $service, array $payload): array
    {
        $response = Http::withHeaders($this->getAuthHeaders())
            ->post($this->buildEndpoint($service), $payload)
            ->json();
        return $response ?? [];
    }

    public function checkBalance(): string
    {
        try {
            $res = Http::withHeaders($this->getAuthHeaders())
                ->get($this->baseUrl() . '/balance')
                ->json();

            return isset($res['contents']['balance']) ? (float) $res['contents']['balance'] : 0;
        } catch (\Throwable $th) {
            Log::error("VTPass balance error: " . $th->getMessage());
            return 0;
        }
    }

    public function verifyTransaction(string $tx_ref): array
    {
        $res = Http::withHeaders($this->getAuthHeaders())
            ->get($this->baseUrl() . "/requery?request_id={$tx_ref}")
            ->json();
        return $res ?? [];
    }

    /**
     * VTPass authenticates every request via the api-key/public-key headers
     * directly (see getAuthHeaders()) rather than a login/token exchange, so
     * this just confirms those credentials actually work — used by
     * isHealthy() for the admin connection-status check. This method was
     * missing entirely before, which left VendorInterface partially
     * unimplemented and made the class fatal to instantiate at all.
     */
    public function login(): array
    {
        try {
            $res = Http::withHeaders($this->getAuthHeaders())
                ->get($this->baseUrl() . '/balance')
                ->json();

            return isset($res['contents']['balance']) ? ['status' => 'success'] : ['status' => 'fail'];
        } catch (\Throwable $th) {
            Log::error("VTPass login/ping error: " . $th->getMessage());
            return ['status' => 'fail'];
        }
    }

    protected function getAuthHeaders(): array
    {
        return [
            'api-key' => $this->provider->api_key,
            'public-key' => $this->provider->public_key,
            'Content-Type' => 'application/json',
        ];
    }

    protected function baseUrl(): string
    {
        return $this->provider->base_url;
    }

    protected function getSupportedServices(): array
    {
        return [
            'airtime',
            'data',
            'cable',
            'electricity',
            'exam'
        ];
    }

    protected function pingEndpoint(): string
{
    // Using balance endpoint as health check for API availability
    return $this->baseUrl() . '/balance';
}


    protected function endpoint(string $service): string
    {
        return match($service) {
            'airtime'     => '/pay',
            'data'        => '/pay',
            'cable'       => '/pay',
            'electricity' => '/pay',
            'exam'        => '/pay',
            default       => throw new \InvalidArgumentException("No endpoint mapped for service [$service]"),
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
                    'request_id' => $payload['tx_ref'],
                    'serviceID' => strtolower($payload['network']),
                    'amount' => $payload['amount'],
                    'phone' => $payload['phone'],
                ];

            case 'data':
                $dataPlan = DataPlan::find($payload['data_plan']);
                if (!$dataPlan) {
                    throw new \InvalidArgumentException("Data plan [{$payload['data_plan']}] not found");
                }
                return [
                    'request_id' => $payload['tx_ref'],
                    'serviceID' => strtolower($payload['network'] . '-data'),
                    'billersCode' => $payload['phone'],
                    'variation_code' => $dataPlan->{str_replace(" ", "_", $this->provider->name)},
                    'amount' => $payload['amount'] ?? null,
                ];

            case 'cable':
                $cablePlan = CablePlan::find($payload['cable_plan']);
                if (!$cablePlan) {
                    throw new \InvalidArgumentException("Cable plan [{$payload['cable_plan']}] not found");
                }
                return [
                    'request_id' => $payload['tx_ref'],
                    'serviceID' => strtolower($payload['cable_network']),
                    'billersCode' => $payload['iuc'],
                    'variation_code' => $cablePlan->{str_replace(" ", "_", $this->provider->name)},
                ];

            case 'electricity':
                // vtpass takes the disco slug directly as serviceID rather
                // than a per-provider numeric ID (unlike Adex/SMEPlug), so
                // no DiscoProviderId lookup is needed here — this used to
                // fetch one anyway and then never use it.
                return [
                    'request_id' => $payload['tx_ref'],
                    'serviceID' => strtolower($payload['disco']),
                    'billersCode' => $payload['meter_number'],
                    'variation_code' => $payload['meter_type'] ?? 'prepaid',
                    'amount' => $payload['amount'],
                ];

            case 'exam':
                return [
                    'request_id' => $payload['tx_ref'],
                    'serviceID' => strtolower($payload['exam_type']),
                    'variation_code' => $payload['variation_code'] ?? '',
                    'amount' => $payload['amount'],
                ];

            default:
                throw new \InvalidArgumentException("Unknown service [$service] for VTPass");
        }
    }

    protected function formatResponse(string $service, array $response): array
    {
        $default = [
            'status' => $response['code'] == '000' ? 'success' : 'fail',
            'transaction_reference' => $response['request_id'] ?? null,
            'payment_reference' => $response['transactionID'] ?? null,
            'response_message' => $response['response_description'] ?? '',
            'completed_at' => now(),
            // $response here is the vendor's raw API reply merged with the
            // original $validated payload (see VendorBase::process) — pass
            // through whatever VTUServicesController computed (e.g. the
            // Bill Plan fee for electricity) instead of always zeroing it.
            'service_fee' => (float) ($response['service_fee'] ?? 0),
            'platform' => 'api',
        ];

        // Was missing entirely — formatResponse() never set transaction_type
        // for any service, and it's a NOT NULL enum column on `transactions`,
        // so every single vtpass transaction failed at the database insert
        // with a truncation/constraint error right after a real vendor call
        // had already gone through.
        $transactionTypes = [
            'airtime' => 'airtime_recharge',
            'data' => 'data_subscription',
            'cable' => 'cable_subscription',
            'electricity' => 'electric_bill',
            'exam' => 'exam',
        ];

        if (!isset($transactionTypes[$service])) {
            throw new \InvalidArgumentException("No response formatter defined for service [$service]");
        }

        return array_merge($default, [
            'transaction_type' => $transactionTypes[$service],
            'amount' => $response['amount'] ?? 0,
            'receiver' => $response['phone'] ?? $response['billersCode'] ?? null,
            'token' => $response['token'] ?? null,
        ]);
    }

    function verifyUser(string $service, string $identifier, array $payload): JsonResponse
    {
        if (!in_array($service, ['cable', 'electricity'])) {
            return $this->fail([], "Verification not supported for service: $service");
        }

        $url = $this->baseUrl() . '/merchant-verify';
        // $payload comes straight from VTUServicesController::verify() —
        // 'cable_network'/'disco', never a 'serviceID' key, so that always
        // resolved to null here (verification would always fail against
        // the real vtpass endpoint). Build it the same way formatPayload()
        // does for the actual purchase, so verify checks the same billing ID.
        $serviceId = $service === 'cable'
            ? strtolower($payload['cable_network'] ?? '')
            : strtolower($payload['disco'] ?? '');
        $data = [
            'billersCode' => $identifier,
            'serviceID' => $serviceId,
        ];

        try {
            $response = Http::withHeaders($this->getAuthHeaders())->post($url, $data);

            if ($response->ok() && $response->json('code') === '000') {
                return $this->success(['name' => $response->json('content.Customer_Name')], ucfirst($service) . ' verification successful.', 201);
            }
            return $this->fail([], $response->json('response_description') ?? 'Verification failed.');
        } catch (\Exception $e) {
            return $this->fail([], $e->getMessage());
        }
    }

    protected function getPlans(?array $payload = null): JsonResponse
    {
        // universalGet() is an instance method, not static — calling it via
        // `AdminController::universalGet(...)` throws "Non-static method
        // ... cannot be called statically" the moment this actually runs.
        return (new AdminController())->universalGet($payload['request'], $payload['table']);
    }

    function callback(Request $request): array
    {
        return [
            "status" => $request->code == '000' ? 'success' : 'fail',
            "tx_ref" => $request['request_id'],
        ];
    }
}