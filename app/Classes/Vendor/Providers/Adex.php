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
        // Was logging the full raw payload/response unconditionally on every
        // call — customer phone/meter/IUC numbers on every purchase, forever.
        // Debug-level so it's still there when actually needed but doesn't
        // spam production logs.
        Log::debug("Adex request [{$service}]", ['payload' => $payload]);
        $response = Http::withHeaders($this->getAuthHeaders())
            ->post($this->buildEndpoint($service), $payload)
            ->json();
        Log::debug("Adex response [{$service}]", ['response' => $response]);

        // ->json() returns null on a non-JSON/empty body (e.g. the vendor
        // API times out or errors below the HTTP client's own retry). Base::
        // process() does array_merge($response['data'] ?? $response, ...)
        // right after this — array_merge(null, ...) is a fatal TypeError,
        // not something the outer try/catch used to be able to soften.
        return $response ?? [];
    }

    public function checkBalance(): string
    {
       try {
         $res = $this->login();
        // normalizeAmount handles comma-grouped strings ("4,495") that a
        // plain (float) cast would truncate to 4.
        return (string) $this->normalizeAmount($res['balance'] ?? 0);
       } catch (\Throwable $th) {
        //throw $th;
        return "0";
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

        $cached = Cache::get($key);
        if ($cached) {
            return $cached;
        }

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Basic ' . base64_encode(
                    $this->provider->username . ':' . $this->provider->password
                ),
                'Content-Type' => 'application/json',
            ])->post($this->baseUrl() . "/user");

            $data = $response->json();

            // Cache::remember() here used to cache whatever the closure
            // returned, including [] on a failed/empty login — turning one
            // transient outage into 5 minutes of every subsequent purchase
            // authenticating with a blank token. Only a real access token is
            // worth caching.
            if (!empty($data['AccessToken'])) {
                Cache::put($key, $data, now()->addMinutes(5));
            }

            return $data ?? [];
        } catch (\Throwable $th) {
            Log::warning('Adex login failed', ['error' => $th->getMessage()]);
            return [];
        }
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
                if (!$dataPlan) {
                    throw new \InvalidArgumentException("Data plan [{$payload['data_plan']}] not found");
                }
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
                if (!$cablePlan) {
                    throw new \InvalidArgumentException("Cable plan [{$payload['cable_plan']}] not found");
                }
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
            // Falls back to the vendor identifier (e.g. "adex") — exam/
            // bulksms/data_card/recharge_card don't set their own 'provider'
            // below, so this was silently leaving those transactions with
            // provider=null instead of at least naming which vendor handled them.
            'provider' => $this->providerName,
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
            // Every case below sets its own transaction_type, so this is
            // never actually used — left null (not a specific, misleading
            // guess) in case a future service case is ever added without one.
            "transaction_type" => null,
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
                    // The real discount is already computed server-side
                    // (Discount::getDiscountedAmount, before this vendor
                    // call ever happens) — "charges" here is whatever fee
                    // Adex applied on their own end, which is a cost, not a
                    // discount. Mapping it to discount_amount would have
                    // reduced what the customer is charged by the vendor's
                    // OWN fee — the opposite of what it should do.
                    'discount_amount' => 0.00,
                    'quantity' => 1.00,
                    'status' => $response['status'] ?? 'failed',
                    'receiver' => $response['iuc'] ?? null,
                    'plan_type' => $response['plan_name'] ?? null,
                    'token' => null, // Not applicable for cable
                ];
                break;

            case 'electricity':
                $result = [
                    // Was 'electricity_bill' — not a real value in the
                    // transactions.transaction_type enum ('electric_bill'
                    // is), so every electricity purchase through Adex failed
                    // at the database insert right after the vendor call
                    // had already gone through.
                    'transaction_type' => 'electric_bill',
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
                    // Was 'data_card' — not a real transactions.transaction_type
                    // enum value; 'data_pin' already means exactly this
                    // (a data PIN voucher) and is used that way elsewhere.
                    'transaction_type' => 'data_pin',
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
                    // Was 'recharge_card' — not a real transactions.transaction_type
                    // enum value; 'airtime_pin' already means exactly this
                    // (an airtime PIN voucher) and is used that way elsewhere.
                    'transaction_type' => 'airtime_pin',
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
            // Was reading from an undefined $options variable (this method's
            // parameter is $payload), so meter_type silently always fell
            // back to 'prepaid' regardless of what the customer actually
            // selected — postpaid meters were verified against the wrong type.
            $meterType = $payload['meter_type'] ?? 'prepaid';
            if (!$discoId) {
            return $this->fail([], "Service type not given");
            }
            $url = $this->baseUrl() . "/bill/bill-validation?meter_number={$identifier}&disco={$discoId}&meter_type={$meterType}";
        } else {
            return $this->fail([], "Verification not supported for service: $service");
        }

        try {
            $response = Http::get($url);
            Log::debug('Adex verifyUser response', ['response' => $response->json()]);

            if ($response->ok() && $response->json('status') === 'success') {
                return $this->success(['name' => $response->json('name')], ucfirst($service) . ' verification successful.', 201);
            }
            return $this->fail([], $response->json('message') ?? 'Verification failed.');
        } catch (\Throwable $e) {
            Log::warning('Adex verifyUser failed', ['error' => $e->getMessage()]);
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
