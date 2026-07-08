<?php

namespace App\Classes\Vendor\Providers;

use App\Classes\Vendor\VendorBase;
use App\Http\Controllers\AdminController;
use App\Models\DataPlan;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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

        if (!$response) {
            // ->json() returns null on a non-JSON/empty body. VendorBase::process()
            // does array_merge($response['data'] ?? $response, ...) right after
            // this — array_merge(null, ...) would be a fatal TypeError.
            return [];
        }

        // Ogdams nests the real payload (msg/ref/...) under a top-level
        // 'data' key, e.g. {status, code, data: {msg, ref}}. VendorBase::
        // process() does array_merge($response['data'] ?? $response, $payload)
        // right after this returns — since 'data' exists, it picks THAT as the
        // merge base and silently drops the sibling 'status'/'code' fields
        // that formatResponse() below depends on. Flatten here so both the
        // envelope (status/code) and the nested payload survive together.
        $flat = array_merge($response, $response['data'] ?? []);
        unset($flat['data']);

        return $flat;
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
                // Unlike Adex/SMEPlug/vtpass (which predate it and still read
                // a legacy per-provider-name column), the admin "Data Plans"
                // UI only writes a vendor's plan ID via the providerables
                // pivot (provider_id + server_id) — there's no field to edit
                // a legacy `ogdams` column at all, so reading one here would
                // always be null. Read from the pivot instead — the same
                // place syncPlans() below writes to.
                $vendorPlanId = $dataPlan->providers()
                    ->wherePivot('provider_id', $this->provider->id)
                    ->first()?->pivot?->server_id;
                if (!$vendorPlanId) {
                    throw new \InvalidArgumentException("Data plan [{$dataPlan->id}] has no Ogdams plan ID configured");
                }
                return [
                    'networkId' => $this->networkIDs[$payload['network']],
                    'planId' => $vendorPlanId,
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
            // Post-flatten (see sendRequest()), 'msg' lives at the top level —
            // the 'data.msg' path no longer exists, kept only as a defensive
            // fallback in case this is ever called with a raw, unflattened
            // response (e.g. directly from a test).
            'response_message' => $response['msg'] ?? $response['data']['msg'] ?? null,
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
     * Pulls the live plan catalogue from Ogdams and returns it as a flat
     * list — the raw shape groups plans by network name:
     * { status, data: { MTN: [{networkId, planId, name, validity, currency,
     * telcoPrice, ourPrice, type}], AIRTEL: [...], ... } }
     */
    public function fetchRemotePlans(): array
    {
        $response = Http::withHeaders($this->getAuthHeaders())
            ->get($this->baseUrl() . '/api/v4/get/data/plans')
            ->json();

        if (!($response['status'] ?? false) || !is_array($response['data'] ?? null)) {
            throw new \RuntimeException('Ogdams plan list request failed or returned an unexpected shape.');
        }

        $flat = [];
        foreach ($response['data'] as $networkName => $plans) {
            $network = strtolower($networkName);
            if (!isset($this->networkIDs[$network])) {
                // A network Ogdams supports that we don't sell — skip rather
                // than guess, so it doesn't get silently created as a plan
                // for a network no purchase form can even select.
                continue;
            }
            foreach ((array) $plans as $plan) {
                $flat[] = [
                    'network' => $network,
                    'vendor_plan_id' => (string) ($plan['planId'] ?? ''),
                    'name' => (string) ($plan['name'] ?? ''),
                    'validity' => (string) ($plan['validity'] ?? ''),
                    'plan_type' => strtoupper((string) ($plan['type'] ?? '')),
                    // ourPrice is what Ogdams actually bills us; telcoPrice
                    // has shown up as "0.00"/null on several sample plans
                    // above, so it isn't a reliable cost — fall back to it
                    // only when ourPrice itself is missing.
                    'cost_price' => (float) ($plan['ourPrice'] ?? $plan['telcoPrice'] ?? 0),
                ];
            }
        }

        return $flat;
    }

    /**
     * Upserts the live Ogdams catalogue into local DataPlan rows, so a
     * purchase never has to call Ogdams to know what plans exist (see
     * formatPayload()'s data case, which reads the plan ID straight off the
     * providerables pivot this method writes to).
     *
     * A DataPlan <-> provider link is a single row keyed only on
     * (providerable_id, providerable_type) — NOT also on provider_id (see
     * AdminController::syncModelRelations) — so this only ever touches rows
     * already linked to *this* Ogdams provider, or brand-new rows it creates
     * itself. It never repoints a plan some other vendor already owns.
     *
     * New plans land inactive + is_draft=true so they don't go on sale with
     * a default ₦0 markup before an admin has priced them.
     */
    public function syncPlans(): array
    {
        $remotePlans = $this->fetchRemotePlans();
        $summary = ['created' => 0, 'updated' => 0, 'skipped' => 0];

        foreach ($remotePlans as $remote) {
            if ($remote['vendor_plan_id'] === '') {
                $summary['skipped']++;
                continue;
            }

            // Amount + unit out of e.g. "500MB [SME]" / "1.5GB [SME]" — same
            // split the admin form itself uses (plan_name = amount,
            // plan_size = unit).
            if (!preg_match('/([\d.]+)\s*(MB|GB)/i', $remote['name'], $m)) {
                $summary['skipped']++;
                continue;
            }
            [, $amount, $unit] = $m;

            $existingLink = DB::table('providerables')
                ->where('providerable_type', DataPlan::class)
                ->where('provider_id', $this->provider->id)
                ->where('server_id', $remote['vendor_plan_id'])
                ->first();

            if ($existingLink) {
                DataPlan::where('id', $existingLink->providerable_id)->update([
                    'validity' => $remote['validity'],
                ]);
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

        return $summary;
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
