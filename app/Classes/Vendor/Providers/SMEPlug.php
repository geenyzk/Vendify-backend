<?php

namespace App\Classes\Vendor\Providers;

use App\Classes\Vendor\VendorBase;
use App\Http\Controllers\AdminController;
use App\Models\DataPlan;
use App\Models\DiscoProviderId;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

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
            ->post($this->buildEndpoint($service), $payload)
            ->json();

        // ->json() returns null on a non-JSON/empty body. VendorBase::process()
        // does array_merge($response['data'] ?? $response, ...) right after
        // this — array_merge(null, ...) is a fatal TypeError.
        return $response ?? [];
    }

    public function checkBalance(): string
    {
        $res = $this->login();
        // Normalize so a comma-grouped balance ("4,495") isn't later
        // truncated to 4 by a downstream (float) cast.
        return (string) $this->normalizeAmount($res['balance'] ?? 0);
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
        // Was also claiming 'electricity', but endpoint() has no mapping
        // for it and formatResponse()'s switch has no case for it either —
        // supportsService('electricity') would say yes and then the actual
        // purchase would throw. Only claim what's actually implemented
        // below; add 'electricity' back once endpoint()/formatPayload()/
        // formatResponse() all genuinely support it.
        return ['airtime', 'data'];
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
                $planId = $this->configuredPlanId($dataPlan)
                    ?? $dataPlan->{str_replace(" ", "_", $this->provider->name)};
                return [
                    'network_id' => $this->networkIDs[$payload['network']],
                    'phone' => $payload['phone'],
                    'amount' => $payload['amount'],
                    'customer_reference' => $payload['tx_ref'],
                ];
            case 'data':
                $dataPlan = DataPlan::find($payload['data_plan']);
                if (!$dataPlan) {
                    throw new \InvalidArgumentException("Data plan [{$payload['data_plan']}] not found");
                }
                return [
                    'network_id' => $this->networkIDs[$payload['network']],
                    'phone' => $payload['phone'],
                    'plan_id' => $planId,
                    'customer_reference' => $payload['tx_ref'],
                ];
            default:
                // Was "for Adex" — copy-pasted from that class, wrong provider name.
                throw new \InvalidArgumentException("Unknown service [$service] for SMEPlug");
        }
    }

    protected function formatResponse(string $service, array $response): array
    {
        $default = [
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
        // Was $payload['serviceType'], a key VTUServicesController::verify()
        // never actually sends (it sends 'cable_network'/'disco', matching
        // Adex's and vtpass's verifyUser()) — always undefined, so this
        // always fell through to "Service type not given" regardless of
        // what the customer picked.
        $cableId = $this->cableNetworkIDs[$payload['cable_network']] ?? null;
        if (!$cableId) {
            return $this->fail([], "Service type not given");
        }
        $url = $this->baseUrl() . "/cable/cable-validation?iuc={$identifier}&cable={$cableId}";
        } elseif ($service === 'electricity') {
            $disco = DiscoProviderId::forDisco($payload['disco']);
            $discoId = $disco->{str_replace(" ", "_", $this->provider->name)} ?? null;
            // Was reading from an undefined $options variable — this
            // method's parameter is $payload — so meter_type silently
            // always defaulted to 'prepaid'.
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
            // universalGet() is an instance method — calling it via
            // `AdminController::universalGet(...)` throws "Non-static
            // method ... cannot be called statically" the moment this runs.
            return (new AdminController())->universalGet($payload['request'], $payload['table']);
        }

        function callback(Request $request): array
        {
            return [
                "tx_ref" => $request->customer_reference,
                "status" => $request->status
            ];
        }
    }
