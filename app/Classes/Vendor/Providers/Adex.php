<?php

namespace App\Classes\Vendor\Providers;


use App\Classes\Vendor\VendorBase;
use App\Http\Controllers\AdminController;
use App\Models\CablePlan;
use App\Models\DataPlan;
use App\Models\DiscoProviderId;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class Adex extends VendorBase
{
    protected string $providerName = 'adex';
    private ?string $accessToken = null;


    function sendRequest(string $service, array $payload): array
    {
        Log::info($payload);
        $response = Http::withHeaders($this->getAuthHeaders())
        ->post($this->buildEndpoint($service), $payload)->json();

        Log::info($response);
        return $response;
    }

    public function checkBalance(): string
    {
        try {
            $res = $this->login();
             $cleaned = preg_replace('/[^\d.]/', '', $res['balance']);
            return (float) $cleaned;
        } catch (\Throwable $th) {
            // Log the exception if needed: error_log($th->getMessage());
            return 0;
        }
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
            'Authorization' => 'Token ' . $this->accessToken,
            'Content-Type' => 'application/json'
        ];
    }

    protected function baseUrl(): string
    {
        return $this->provider->base_url;
    }

     function login(): array
    {
        $key = md5($this->baseUrl() . $this->provider->username . $this->provider->password);
        return Cache::remember($key, now()->addMinutes(5), function (){
                try {
                    $response = Http::withHeaders([
                        'Authorization' => 'Basic ' . base64_encode(
                            $this->provider->username . ':' . $this->provider->password
                        ),
                        'Content-Type' => 'application/json',
                    ])->post($this->baseUrl() . "/user");

                    $data = $response->json();
                    return $data ?? [];
                } catch (\Throwable $th) {
                    //throw $th;
                    Log::info(["login" => $th->getMessage()]);

                    return [];
                }
        });
    }

    protected function getSupportedServices(): array
    {
        return [
            'airtime',
            'data',
            'cable',
            'electricity',
            'exam',
            'bulksms',
            "data_card",
            "recharge_card",
        ];
    }

     protected function pingEndpoint(): string
    {
        return $this->baseUrl() . '/user';
    }

    protected function endpoint(string $service) : string {
            return match($service){
            'airtime' => '/topup',
            'data' => '/data',
            'cable' => '/cable',
            'electricity' => '/bill',
            'exam' => '/exam',
            'bulksms' => '/bulksms',
            'data_card' => '/data_card',
            'recharge_card' => '/recharge_card',
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
                    'network' => $this->networkIDs[$payload['network']],
                    'phone' => $payload['phone'],
                    'plan_type' => $payload['network_type'] ?? 'VTU',
                    'amount' => $payload['amount'],
                    'bypass' => filter_var($payload['bypass'] ?? false, FILTER_VALIDATE_BOOLEAN),
                    'request-id' => $payload['tx_ref'],
                ];
            case 'data':
                $dataPlan = DataPlan::find($payload['data_plan']);
                return [
                    'network' => $this->networkIDs[$payload['network']],
                    'phone' => $payload['phone'],
                    'plan_type' => $payload['plan_type'] ?? 'GIFTING',
                    'data_plan' => $dataPlan->{str_replace(" ", "_", $this->provider->name)},
                    'bypass' => filter_var($payload['bypass'] ?? false, FILTER_VALIDATE_BOOLEAN),
                    'request-id' => $payload['tx_ref'],
                ];
            case 'cable':
                $cablePlan = CablePlan::find($payload['cable_plan']);
                return [
                    'cable' => $this->networkIDs[$payload['cable_network']],
                    'iuc' => $payload['iuc'],
                    'cable_plan' => $cablePlan->{str_replace(" ", "_", $this->provider->name)},
                    'bypass' => filter_var($payload['bypass'] ?? false, FILTER_VALIDATE_BOOLEAN),
                    'request-id' => $payload['tx_ref'],
                ];

            case 'electricity':
                $disco = DiscoProviderId::forDisco($payload['disco']);
                $discoId = $disco->{str_replace(" ", "_", $this->provider->name)} ?? null;

                if (!$discoId) {
                    throw new \InvalidArgumentException("Invalid DISCO provider ID");
                }

                return [
                    'disco' => $discoId,
                    'meter_type' => $payload['meter_type'] ?? 'prepaid',
                    'meter_number' => $payload['meter_number'],
                    'amount' => $payload['amount'],
                    'bypass' => filter_var($payload['bypass'] ?? false, FILTER_VALIDATE_BOOLEAN),
                    'request-id' => $payload['tx_ref'],
                ];

            case 'exam':
                return [
                    'quantity' => $payload['quantity'] ?? 1,
                    'request-id' => $payload['tx_ref'],
                ];

            case 'bulksms':
                return [
                    'sender_name' => $payload['sender'] ?? 'API',
                    'numbers' => is_array($payload['numbers']) ? implode(',', $payload['numbers']) : $payload['numbers'],
                    'message' => $payload['message'] ?? '',
                    'request-id' => $payload['tx_ref'],
                ];

            case 'data_card':
                return [
                    'network' => $payload['network'],
                    'plan_type' => $payload['plan_type'],
                    'quantity' => $payload['quantity'],
                    'card_name' => $payload['card_name'],
                    'request-id' => $payload['tx_ref'] ?? uniqid('Data_card_'),
                ];

            case 'recharge_card':
                return [
                    'network' => $payload['network'], // assuming the API expects string like "MTN"
                    'plan_type' => $payload['plan_type'] ?? null,
                    'quantity' => (int)($payload['quantity'] ?? 1),
                    'card_name' => $payload['card_name'] ?? null,
                    'request-id' => $payload['tx_ref'],
                    'amount' => $payload['amount'] ?? null,
                ];
            default:
                throw new \InvalidArgumentException("Unknown service [$service] for Adex");
        }
    }

    protected function formatResponse(string $service, array $response): array
    {
        $default = [
            'provider' =>  null,//$this->providerName,
            'status' => 'fail', // default unless confirmed otherwise
            'transaction_reference' => $response['request-id'] ?? $response['tx_ref'] ?? null,
            'payment_reference' => $response['reference'] ?? null,
            'response_message' => $response['message'] ?? 'Transaction failed',
            'completed_at' => now(),
            // $response is the raw vendor reply merged with the original
            // $validated payload — pass through whatever
            // VTUServicesController computed (e.g. the Bill Plan fee for
            // electricity) instead of always zeroing it.
            'service_fee' => (float) ($response['service_fee'] ?? 0),
            'platform' => 'api',
            "transaction_type" => "data_subscription"
        ];

        switch ($service) {
            case 'airtime':
                $result = [
                    'provider' => $response['network'],
                    'transaction_type' =>'airtime_recharge',
                    'account_or_phone' => $response['phone_number'] ?? null,
                    'amount' => $response['amount'] ?? 0.00,
                    'discount_amount' => $response['discount_amount'],
                    'quantity' => 1.00,
                    'status' => $response['status'],
                    'receiver' => $response['phone_number'] ?? null,
                    'plan_type' => $response['plan_type'] ?? 'VTU',
                    'token' => null,
                ];
                break;

            case 'data':
                $result = [
                    'provider' => $response['network'],
                    'transaction_type' =>'data_subscription',
                    'account_or_phone' => $response['phone_number'] ?? null,
                    'amount' => $response['amount'] ?? 0.00,
                    'discount_amount' => 0.00,
                    'quantity' => $this->convertDataPlanToGB($response['dataplan']),
                    'status' => $response['status'],
                    'receiver' => $response['phone_number'] ?? null,
                    'plan_type' => $response['plan_type'] ?? null,
                    'token' =>  null,
                ];
                break;
            case 'cable':
                $result = [
                    'provider' => $response['cabl_name'],
                    'transaction_type' => 'cable_subscription',
                    'account_or_phone' => $response['iuc'] ?? null,
                    'amount' => (float) ($response['amount'] ?? 0.00),
                    'discount_amount' => (float) ($response['charges'] ?? 0.00),
                    'quantity' => 1.00,
                    'status' => $response['status'] ?? 'failed',
                    'receiver' => $response['iuc'] ?? null,
                    'plan_type' => $response['plan_name'] ?? null,
                    'token' => null, // Not applicable for cable
                ];
                break;

            case 'electricity':
                $result = [
                    'transaction_type' => 'electricity_bill',
                    'account_or_phone' => $response['meter_number'] ?? null,
                    'amount' => $response['amount'] ?? 0.00,
                    'discount_amount' => 0.00,
                    'quantity' => 1.00,
                    'status' => $response['status'] ?? 'fail',
                    'receiver' => $response['meter_number'] ?? null,
                    'plan_type' => $response['meter_type'] ?? null,
                    'token' => $response['token'] ?? null,
                ];
                break;

            case 'exam':
                $result = [
                    'transaction_type' => 'exam',
                    'status' => $response['status'] ?? 'fail',
                    'message' => $response['message'] ?? 'Unknown status',
                    'amount' => $response['amount'] ?? 0,
                    'quantity' => $response['quantity'] ?? 0,
                    'token' => $response['pin'] ?? null, // example: "pin1<=>seral1"
                    'account_or_phone' => $response['username'] ?? null,
                ];
                break;

            case 'bulksms':
                $result = [
                    'transaction_type' => 'bulksms',
                    'status' => $response['status'] ?? 'fail',
                    'message' => $response['message'] ?? 'Failed to send SMS',
                    'amount' => $response['amount'] ?? 0.00,
                    'quantity' => $response['total_number'] ?? 0,
                    'correct_number' => $response['correct_number'] ?? null,
                    'wrong_number' => $response['wrong_number'] ?? null,
                    'sender_name' => $response['sender_name'] ?? null,
                    'numbers' => $response['numbers'] ?? null,
                    'oldbal' => $response['oldbal'] ?? null,
                    'newbal' => $response['newbal'] ?? null,
                ];
                break;

            case 'data_card':
                $result = [
                    'transaction_type' => 'data_card',
                    'status' => $response['status'] ?? 'fail',
                    'message' => $response['message'] ?? '',
                    'amount' => $response['amount'] ?? 0,
                    'quantity' => (int)($response['quantity'] ?? 0),
                    'card_name' => $response['card_name'] ?? null,
                    'serial' => $response['serial'] ?? null,
                    'pin' => $response['pin'] ?? null,
                    'load_pin' => $response['load_pin'] ?? null,
                    'check_balance' => $response['check_balance'] ?? null,
                    'oldbal' => $response['oldbal'] ?? null,
                    'newbal' => $response['newbal'] ?? null,
                ];
                break;

            case 'recharge_card':
                $result = [
                    'transaction_type' => 'recharge_card',
                    'account_or_phone' => null,
                    'amount' => $response['amount'] ?? 0.00,
                    'quantity' => (int) ($response['quantity'] ?? 1),
                    'status' => $response['status'] ?? 'fail',
                    'receiver' => null,
                    'card_name' => $response['card_name'] ?? null,
                    'serials' => $response['serial'] ?? null,
                    'pins' => $response['pin'] ?? null,
                    'load_pin' => $response['load_pin'] ?? null,
                    'check_balance' => $response['check_balance'] ?? null,
                ];
                break;
            default:
                throw new \InvalidArgumentException("No response formatter defined for service [$service]");
        }

        return array_merge($default, $result);
    }

    function verifyUser(string $service, string $identifier, array $payload): JsonResponse
    {
        if ($service == 'cable') {
        $cableId = $this->cableNetworkIDs[$payload['cable_network']] ?? null;
        if (!$cableId) {
            return $this->fail([], "Service type not given");
            // ['status' => 'error', 'message' => 'Cable ID required'];
        }
        $url = $this->baseUrl() . "/cable/cable-validation?iuc={$identifier}&cable={$cableId}";
        } elseif ($service == 'electricity') {
            $disco = DiscoProviderId::forDisco($payload['disco']);
            $discoId = $disco->{str_replace(" ", "_", $this->provider->name)} ?? null;
            Log::info($disco);
            Log::info($discoId);
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
            Log::info(["response: " => $response]);

            if ($response->ok() && $response->json('status') === 'success') {
                return $this->success(['name' => $response->json('name')], ucfirst($service) . ' verification successful.', 201);
            }
            return $this->fail([], $response->json('message') ?? 'Verification failed.');
        } catch (\Exception $e) {
            Log::info(["ERROR: " => $e]);
            return $this->fail([], $e->getMessage());
        }
    }

    protected function getPlans(?array $payload = null): JsonResponse
    {
        $adminController = new AdminController();
        return $adminController->universalGet($payload['request'], $payload['table']);
    }

    function callback(Request $request): array
    {

        return [
            "status" => $request->status,
            "tx_ref" => $request['request-id'],

        ];
    }
}
