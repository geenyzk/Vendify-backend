<?php

namespace App\Classes\Vendor\Providers;

use App\Classes\Vendor\VendorBase;
use App\Http\Controllers\AdminController;
use App\Models\CablePlan;
use App\Models\DataPlan;
use App\Models\Discount;
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
                return [
                    'request_id' => $payload['tx_ref'],
                    'serviceID' => strtolower($payload['network'] . '-data'),
                    'billersCode' => $payload['phone'],
                    'variation_code' => $dataPlan->{str_replace(" ", "_", $this->provider->name)},
                    'amount' => $payload['amount'] ?? null,
                ];

            case 'cable':
                $cablePlan = CablePlan::find($payload['cable_plan']);
                return [
                    'request_id' => $payload['tx_ref'],
                    'serviceID' => strtolower($payload['cable_network']),
                    'billersCode' => $payload['iuc'],
                    'variation_code' => $cablePlan->{str_replace(" ", "_", $this->provider->name)},
                ];

            case 'electricity':
                $disco = Discount::getElectricity($payload['disco']);
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
            'service_fee' => 0.00,
            'platform' => 'api',
        ];

        return match($service) {
            'airtime', 'data', 'cable', 'electricity', 'exam' => array_merge($default, [
                'amount' => $response['amount'] ?? 0,
                'receiver' => $response['phone'] ?? $response['billersCode'] ?? null,
                'token' => $response['token'] ?? null,
            ]),
            default => $default,
        };
    }

    function verifyUser(string $service, string $identifier, array $payload): JsonResponse
    {
        if (!in_array($service, ['cable', 'electricity'])) {
            return $this->fail([], "Verification not supported for service: $service");
        }

        $url = $this->baseUrl() . '/merchant-verify';
        $data = [
            'billersCode' => $identifier,
            'serviceID' => $payload['serviceID'],
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
        return AdminController::universalGet($payload['request'], $payload['table']);
    }

    function callback(Request $request): array
    {
        return [
            "status" => $request->code == '000' ? 'success' : 'fail',
            "tx_ref" => $request['request_id'],
        ];
    }
}