<?php

namespace App\Class\Vendor\Providers;

use App\Class\Vendor\VendorBase;
use App\Http\Controllers\AdminController;
use App\Models\DataPlan;
use App\Models\Discount;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SMEPlug extends VendorBase
{
    protected string $providerName = 'sme_plug';
    private ?string $accessToken = null;

    protected array $networkIDs = [
        'mtn' => 1,
        'airtel' => 2,
        'glo' => 4,
        '9mobile' => 3,
    ];


    function sendRequest(string $service, array $payload): array
    {
        $response = Http::withHeaders($this->getAuthHeaders())
        ->post($this->buildEndpoint($service), $payload)->json();
        return $response;
    }

    public function checkBalance(): string
    {
        // $res = $this->login();
        // return $res['balance'] ?? "";
        return "0.0";
    }

     public function verifyTransaction(string $tx_ref): array
    {
        return[];
    }


    protected function getAuthHeaders(): array
    {
        if (!$this->accessToken) {
            $this->accessToken = $this->login()['AccessToken'] ?? null;
        }

        return [
            'Authorization' => 'Bearer ' . $this->provider->api_key,
            'Content-Type' => 'application/json'
        ];
    }

    protected function baseUrl(): string
    {
        return $this->provider->base_url;
    }

     function login(): array
    {
        return  [];
    }

    protected function getSupportedServices(): array
    {
        return ['airtime', 'data', 'electricity'];
    }

     protected function pingEndpoint(): string
    {
        return $this->baseUrl() . '/user';
    }

    protected function endpoint(string $service) : string {
            return match($service){
            'airtime' => '/airtime/purchase',
            'data' => '/data/purchase',
            default => throw new \InvalidArgumentException("No endpoint mapped for service [$service]")
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
                    'network_id' => $this->networkIDs[$payload['network']],
                    'phone' => $payload['phone'],
                    'amount' => $payload['amount'],
                    'customer_reference' => $payload['tx_ref'],
                ];
            case 'data':
                $dataPlan = DataPlan::find($payload['data_plan']);
                return [
                    'network_id' => $this->networkIDs[$payload['network']],
                    'phone' => $payload['phone'],
                    'plan_id' => $dataPlan->{str_replace(" ", "_", $this->provider->name)},
                    'customer_reference' => $payload['tx_ref'],
                ];
            default:
                throw new \InvalidArgumentException("Unknown service [$service] for Adex");
        }
    }

    protected function formatResponse(string $service, array $response): array
    {
        $
        $default = [
            'provider' => $this->providerName,
            'status' => 'fail', // default unless confirmed otherwise
            'transaction_reference' => $response['request-id'] ?? $response['tx_ref'] ?? null,
            'payment_reference' => $response['reference'] ?? null,
            'response_message' => $response['message'] ?? 'Transaction failed',
            'completed_at' => now(),
            'service_fee' => 0.00,
            'platform' => 'api',
            "transaction_type" => "data_subscription"
        ];

        switch ($service) {
            case 'airtime':
                $result = [
                    'transaction_type' =>'airtime_recharge',
                    'account_or_phone' => $response['phone'] ?? null,
                    'amount' => $response['amount'] ?? 0.00,
                    'discount_amount' => $response['discount_amount'],
                    'quantity' => 1.00,
                    'status' => $response['status'],
                    'receiver' => $response['phone'] ?? null,
                    'plan_type' => $response['plan_type'] ?? 'VTU',
                    'token' => null,
                ];
                break;

            case 'data':
                $result = [
                    'transaction_type' =>'data_subscription',
                    'account_or_phone' => $response['phone'] ?? null,
                    'amount' => $response['amount'] ?? 0.00,
                    'discount_amount' => 0.00,
                    'quantity' => 1.00,
                    'status' => $response['status'],
                    'receiver' => $response['phone'] ?? null,
                    'plan_type' => $response['plan_type'] ?? 'GIFTING',
                    'token' => $response['token'] ?? null,
                ];
                break;

            default:
                throw new \InvalidArgumentException("No response formatter defined for service [$service]");
        }

        return array_merge($default, $result);
    }

    function verifyUser(string $service, string $identifier, array $payload): JsonResponse
    {
        if ($service === 'cable') {
        $cableId = $this->cableNetworkIDs[$payload['serviceType']] ?? null;
        if (!$cableId) {
            return $this->fail([], "Service type not given");
            // ['status' => 'error', 'message' => 'Cable ID required'];
        }
        $url = $this->baseUrl() . "/cable/cable-validation?iuc={$identifier}&cable={$cableId}";
        } elseif ($service === 'electricity') {
            $disco = Discount::getElectricity($payload['serviceType']);
            $discoId = $disco->{str_replace(" ", "_", $this->provider->name)} ?? null;
            $meterType = $options['meter_type'] ?? 'prepaid';
            if (!$discoId) {
            return $this->fail([], "Service type not given");
            }
            $url = $this->baseUrl() . "/bill/bill-validation?meter_number={$identifier}&disco={$discoId}&meter_type={$meterType}";
        } else {
            return $this->fail([], "Verification not supported for service: $service");
        }

        try {
            $response = Http::get($url);

            if ($response->ok() && $response->json('status') === 'success') {
                return $this->success(['name' => $response->json('name')], ucfirst($service) . ' verification successful.', 201);
            }
            return $this->fail([], $response->json('message') ?? 'Verification failed.');
        } catch (\Exception $e) {
            return $this->fail([], $e->getMessage());
        }
        }

        protected function getPlans(?array $payload = null): JsonResponse
        {
            return  AdminController::universalGet($payload['request'], $payload['table']);
        }

        function callback(Request $request): array
        {
            return [
                "tx_ref" => $request->customer_reference,
                "status" => $request->status
            ];
        }
    }
