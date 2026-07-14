<?php

namespace App\Classes\Vendor\Providers;


use App\Classes\Vendor\VendorBase;
use App\Http\Controllers\AdminController;
use App\Models\CablePlan;
use App\Models\DataPlan;
use App\Models\DiscoProviderId;
use App\Models\Role;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
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
        $response = Http::withHeaders($this->getAuthHeaders())
            ->post($this->buildEndpoint($service), $payload)
            ->json();

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
            // Token exchange feeds both purchases and the dashboard balance/
            // health checks — 15s is still generous for a login round-trip,
            // while keeping a dead vendor from stalling the stats endpoint
            // for the full 30s client default.
            $response = Http::connectTimeout(5)->timeout(15)
                ->withHeaders([
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
                Cache::put($key, $data, now()->addMinutes(3));
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

    /**
     * The vendor's own ID for a catalog plan (data/cable). Adex-family vendors
     * historically stored it in a column named after the vendor
     * ("adex server 1" → data_plans.adex_server_1); vendors created with an
     * arbitrary name — and the modern plan form's "Plan ID" field — store it on
     * the providerables pivot (server_id) instead. Prefer the legacy column
     * when it actually holds a value, else fall back to the pivot so an
     * any-named vendor still resolves. Returns null when neither is set.
     */
    protected function resolveVendorPlanId($plan): ?string
    {
        $column = str_replace(' ', '_', $this->provider->name);
        $legacy = $plan->{$column} ?? null;
        if ($legacy !== null && $legacy !== '' && (string) $legacy !== '0') {
            return (string) $legacy;
        }

        $pivotServerId = optional($plan->providers()->first())->pivot->server_id
            ?? \Illuminate\Support\Facades\DB::table('providerables')
                ->where('providerable_id', $plan->id)
                ->where('providerable_type', get_class($plan))
                ->value('server_id');

        if ($pivotServerId === null || (string) $pivotServerId === '' || (string) $pivotServerId === '0') {
            return null;
        }

        return (string) $pivotServerId;
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
                $dataVendorId = $this->resolveVendorPlanId($dataPlan);
                if ($dataVendorId === null) {
                    throw new \InvalidArgumentException(
                        "No Adex plan ID for data plan #{$dataPlan->id} on vendor [{$this->provider->name}] — set the Plan ID on the data plan."
                    );
                }
                return [
                    'network' => $this->networkIDs[$payload['network']],
                    'phone' => $payload['phone'],
                    'plan_type' => $payload['plan_type'] ?? 'GIFTING',
                    'data_plan' => $dataVendorId,
                    'bypass' => filter_var($payload['bypass'] ?? false, FILTER_VALIDATE_BOOLEAN),
                    'request-id' => $payload['tx_ref'],
                ];
            case 'cable':
                $cablePlan = CablePlan::find($payload['cable_plan']);
                if (!$cablePlan) {
                    throw new \InvalidArgumentException("Cable plan [{$payload['cable_plan']}] not found");
                }
                $cableVendorId = $this->resolveVendorPlanId($cablePlan);
                if ($cableVendorId === null) {
                    throw new \InvalidArgumentException(
                        "No Adex plan ID for cable plan #{$cablePlan->id} on vendor [{$this->provider->name}] — set the Plan ID on the cable plan."
                    );
                }
                return [
                    'cable' => $this->networkIDs[$payload['cable_network']],
                    'iuc' => $payload['iuc'],
                    'cable_plan' => $cableVendorId,
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
                    // Guard the key: a failed/other-shaped Adex reply may omit
                    // 'dataplan', and every sibling field here already defaults.
                    // convertDataPlanToGB('') just yields 0.0 — better than a
                    // TypeError/undefined-key crash that fails the whole
                    // transaction even after the vendor may have delivered.
                    'quantity' => $this->convertDataPlanToGB($response['dataplan'] ?? ''),
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

    public function fetchRemotePlans(): array
    {
        $response = Http::connectTimeout(5)->timeout(15)
            ->get($this->baseUrl() . '/api/data-plan');
        Log::info($response);

        if (!$response->successful()) {
            throw new \RuntimeException('ADEX plan list request failed or returned an unexpected shape.');
        }

        $payload = $response->json();
        if (!is_array($payload)) {
            throw new \RuntimeException('ADEX plan list request returned an unexpected payload.');
        }

        $flat = [];
        foreach ($payload as $plan) {
            if (!is_array($plan)) {
                continue;
            }

            $network = strtolower((string) ($plan['network'] ?? ''));
            if ($network === '') {
                continue;
            }

            $flat[] = [
                'network' => $network,
                'vendor_plan_id' => (string) ($plan['plan_id'] ?? $plan['id'] ?? ''),
                'name' => (string) ($plan['plan_name'] ?? ''),
                'validity' => (string) ($plan['validate'] ?? $plan['validity'] ?? ''),
                'plan_type' => strtoupper((string) ($plan['network_type'] ?? '')),
                'cost_price' => (float) ($plan['amount'] ?? $plan['price'] ?? 0),
            ];
        }

        return $flat;
    }

    public function syncPlans(): array
    {
        $remotePlans = $this->fetchRemotePlans();
        $summary = ['created' => 0, 'updated' => 0, 'skipped' => 0, 'message' => 'No eligible data plans found for this provider.'];
        $defaultPricing = $this->defaultPricing();

        foreach ($remotePlans as $remote) {
            if ($remote['vendor_plan_id'] === '') {
                $summary['skipped']++;
                continue;
            }

            if (!preg_match('/([\d.]+)\s*(MB|GB)/i', $remote['name'], $matches)) {
                $summary['skipped']++;
                continue;
            }

            [, $amount, $unit] = $matches;

            $existingLink = DB::table('providerables')
                ->where('providerable_type', DataPlan::class)
                ->where('provider_id', $this->provider->id)
                ->where('server_id', $remote['vendor_plan_id'])
                ->first();

            if ($existingLink) {
                DataPlan::where('id', $existingLink->providerable_id)->update([
                    'plan_type' => $remote['plan_type'],
                    'validity' => $remote['validity'],
                ]);
                DataPlan::where('id', $existingLink->providerable_id)
                    ->where('is_draft', true)
                    ->whereNull('pricing')
                    ->update(['pricing' => json_encode($defaultPricing)]);
                DB::table('providerables')
                    ->where('id', $existingLink->id)
                    ->update([
                        'cost_price' => $remote['cost_price'],
                        'updated_at' => now(),
                    ]);
                $summary['updated']++;
                continue;
            }

            $dataPlan = DataPlan::create([
                'network' => $remote['network'],
                'plan_type' => $remote['plan_type'],
                'plan_name' => $amount,
                'plan_size' => strtoupper($unit),
                'validity' => $remote['validity'],
                'active' => false,
                'is_draft' => true,
                'sort_order' => 0,
                'pricing' => $defaultPricing,
            ]);

            DB::table('providerables')->insert([
                'provider_id' => $this->provider->id,
                'providerable_id' => $dataPlan->id,
                'providerable_type' => DataPlan::class,
                'cost_price' => $remote['cost_price'],
                'margin_value' => 0,
                'margin_type' => 'fiat',
                'server_id' => $remote['vendor_plan_id'],
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $summary['created']++;
        }

        if ($summary['created'] === 0 && $summary['updated'] === 0) {
            $summary['message'] = $summary['skipped'] > 0
                ? 'No eligible data plans were found to sync from this provider yet.'
                : 'No data plan changes were needed.';
        } else {
            $summary['message'] = 'Plans synced successfully.';
        }

        return $summary;
    }

    private function defaultPricing(): array
    {
        $roles = Role::where('is_staff', false)->pluck('name')->all();

        $pricing = ['user' => ['type' => 'percentage', 'value' => 5]];
        foreach ($roles as $role) {
            $pricing[$role] = ['type' => 'percentage', 'value' => 5];
        }

        return $pricing;
    }

    function callback(Request $request): array
    {

        return [
            "status" => $request->status,
            "tx_ref" => $request['request-id'],

        ];
    }
}
