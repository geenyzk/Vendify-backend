<?php

namespace App\Classes\Vendor\Providers;

use App\Classes\Vendor\VendorBase;
use App\Models\DataPlan;
use App\Models\CablePlan;
use App\Models\Transaction;
use App\Models\Role;
use App\Models\ServiceRoute;
use App\Models\Provider;
use App\Models\Vendor;
use App\Jobs\ReconcileVTUNgTransaction;
use App\Support\PerformanceCache;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

class VTUNg extends VendorBase
{
    protected string $providerName = 'vtu_ng';

    /** Resolve the same active providers-table row for every recheck path. */
    public static function activeProvider(): ?Vendor
    {
        $normalize = fn ($value): string => strtolower(preg_replace('/[^a-z0-9]+/i', '', (string) $value) ?? '');
        $provider = Provider::query()
            ->where('active', true)
            ->orderBy('id')
            ->get(['id', 'name', 'sub_category'])
            ->first(fn (Provider $candidate) => in_array(
                'vtung',
                [$normalize($candidate->sub_category), $normalize($candidate->name)],
                true,
            ));

        // Vendor's category scope can hide legacy rows that the Provider
        // management model still exposes. Hydrate this exact internal row as
        // Vendor so the existing VTU.ng adapter can use it unchanged.
        return $provider
            ? Vendor::withoutGlobalScopes()->find($provider->id)
            : null;
    }

    protected function baseUrl(): string
    {
        $configured = rtrim((string) ($this->provider->base_url ?: config('services.vtu_ng.base_url')), '/');

        // Accept either documented root form (/wp-json) or API-base form
        // (/wp-json/api/v2), but always vend against the v2 API base.
        return preg_match('#/api/v2$#', $configured)
            ? $configured
            : $configured.'/api/v2';
    }

    protected function rootUrl(): string
    {
        return preg_replace('#/api/v2$#', '', $this->baseUrl()) ?: 'https://vtu.ng/wp-json';
    }

    protected function endpoint(string $service): string
    {
        return match ($service) {
            'airtime' => '/airtime',
            'data' => '/data',
            'cable' => '/tv',
            'electricity' => '/electricity',
            default => throw new \InvalidArgumentException("VTU.ng does not support service [$service] in this integration."),
        };
    }

    protected function pingEndpoint(): string
    {
        return $this->baseUrl().'/variations/data';
    }

    protected function getSupportedServices(): array
    {
        return ['airtime', 'data', 'cable', 'electricity'];
    }

    protected function getAuthHeaders(): array
    {
        return [
            'Authorization' => 'Bearer '.$this->token(),
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        ];
    }

    private function token(bool $forceRefresh = false): string
    {
        $cacheKey = "vtu-ng:token:{$this->provider->id}";
        if ($forceRefresh) {
            Cache::forget($cacheKey);
        }

        if (! $forceRefresh && ($cached = Cache::get($cacheKey))) {
            return (string) $cached;
        }

        $username = $this->provider->username ?: config('services.vtu_ng.username');
        $password = $this->provider->password ?: config('services.vtu_ng.password');
        if (! $username || ! $password) {
            $managedToken = $this->provider->api_key ?: config('services.vtu_ng.api_token');
            if ($managedToken) return (string) $managedToken;
            throw new \RuntimeException('VTU.ng credentials are not configured.');
        }

        // Only one worker may generate the latest-only-valid JWT at a time.
        return Cache::lock("{$cacheKey}:refresh", 30)->block(10, function () use ($cacheKey, $username, $password) {
            if ($cached = Cache::get($cacheKey)) return (string) $cached;
            $response = Http::connectTimeout(5)->timeout(15)->acceptJson()->post(
                $this->rootUrl().'/jwt-auth/v1/token',
                ['username' => $username, 'password' => $password]
            );

            $token = $response->json('token');
            if (! $response->successful() || ! is_string($token) || $token === '') {
                throw new \RuntimeException('VTU.ng authentication failed.');
            }

            Cache::put($cacheKey, $token, now()->addDays(6));
            return $token;
        });
    }

    private function authenticatedPost(string $url, array $payload)
    {
        $send = fn (string $token) => Http::connectTimeout(8)->timeout(45)
            ->withToken($token)->acceptJson()->post($url, $payload);
        $response = $send($this->token());
        $body = $response->json();
        $code = strtolower((string) (is_array($body) ? ($body['code'] ?? '') : ''));
        $message = (string) (is_array($body) ? ($body['message'] ?? '') : '');
        $invalidToken = $response->status() === 401
            || str_contains($code, 'jwt')
            || str_contains($code, 'token')
            || ($response->status() === 403
                && (bool) preg_match('/token has been invalidated|invalid (?:jwt|token)|token (?:is )?expired/i', $message));

        return $invalidToken ? $send($this->token(true)) : $response;
    }

    public function login(): array
    {
        try {
            $response = Http::connectTimeout(5)->timeout(10)->get($this->pingEndpoint());
            return ['status' => $response->successful() ? 'success' : 'fail'];
        } catch (\Throwable) {
            return ['status' => 'fail'];
        }
    }

    public function checkBalance(): string
    {
        return '0'; // v2 documents no balance endpoint.
    }

    public function sendRequest(string $service, array $payload): array
    {
        try {
            $response = $this->authenticatedPost($this->baseUrl().$this->endpoint($service), $payload);
        } catch (ConnectionException $e) {
            // The request may have reached VTU.ng. Recording pending prevents
            // unsafe provider failover; reconciliation can use /requery.
            return [
                '_transport_ambiguous' => true,
                'message' => 'VTU.ng request outcome is unknown and is awaiting reconciliation.',
                'request_id' => $payload['request_id'] ?? null,
            ];
        }

        $body = $response->json();
        $body = is_array($body) ? $body : [];
        // VendorBase merges response['data'] when present, which would drop
        // the envelope's code/message/status. Flatten the v2 response first.
        if (is_array($body['data'] ?? null)) {
            $body = array_merge($body, $body['data']);
            unset($body['data']);
        }
        $body['_http_status'] = $response->status();

        return $body;
    }

    public function formatPayload(string $service, array $payload): array
    {
        if ($service === 'airtime') {
            return [
                'request_id' => substr('vendify_'.$payload['tx_ref'], 0, 50),
                'phone' => (string) $payload['phone'],
                'service_id' => strtolower((string) $payload['network']),
                'amount' => (int) $payload['amount'],
            ];
        }

        if ($service === 'electricity') {
            return [
                'request_id' => substr('vendify_'.$payload['tx_ref'], 0, 50),
                'customer_id' => (string) $payload['meter_number'],
                'service_id' => $this->electricityServiceId((string) $payload['disco']),
                'variation_id' => strtolower((string) ($payload['meter_type'] ?? 'prepaid')),
                // The upstream receives the value to vend, not Vendify's
                // separately calculated customer-facing service fee.
                'amount' => (int) $payload['amount'],
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
            if (! $mapping || ! $mapping->external_plan_id || ! $mapping->provider_service_id) {
                throw new \InvalidArgumentException("Cable plan [{$plan->id}] has no available VTU.ng mapping.");
            }

            $serviceId = $this->cableServiceId((string) $mapping->provider_service_id);
            $subscriptionType = strtolower((string) ($payload['subscription_type'] ?? 'change'));
            $verification = Cache::get($this->cableVerificationKey((int) Auth::id(), $serviceId, (string) $payload['iuc']));
            if ($serviceId !== 'showmax' && (! is_array($verification) || ($verification['verified'] ?? false) !== true)) {
                throw new \InvalidArgumentException('Please verify this smartcard before purchasing.');
            }
            $request = [
                'request_id' => substr('vendify_'.$payload['tx_ref'], 0, 50),
                'customer_id' => (string) $payload['iuc'],
                'service_id' => $serviceId,
                'variation_id' => (string) $mapping->external_plan_id,
                'subscription_type' => $subscriptionType,
            ];

            if ($subscriptionType === 'renew') {
                $renewalAmount = is_array($verification) ? ($verification['renewal_amount'] ?? null) : null;
                if (! is_numeric($renewalAmount) || (float) $renewalAmount <= 0) {
                    throw new \InvalidArgumentException('Please verify this smartcard again before renewing.');
                }
                $request['amount'] = (float) $renewalAmount;
            }

            return $request;
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

        if (! $mapping || ! $mapping->external_plan_id || ! $mapping->provider_service_id) {
            throw new \InvalidArgumentException("Data plan [{$plan->id}] has no VTU.ng mapping.");
        }

        return [
            'request_id' => substr('vendify_'.$payload['tx_ref'], 0, 50),
            'phone' => $payload['phone'],
            'service_id' => $mapping->provider_service_id,
            'variation_id' => (string) $mapping->external_plan_id,
        ];
    }

    protected function formatResponse(string $service, array $response): array
    {
        $data = is_array($response['data'] ?? null) ? $response['data'] : $response;
        $upstreamStatus = strtolower((string) ($data['status'] ?? ''));
        $httpStatus = (int) ($response['_http_status'] ?? 0);
        $ambiguous = (bool) ($response['_transport_ambiguous'] ?? false);

        $status = match (true) {
            $upstreamStatus === 'completed-api' => 'success',
            in_array($upstreamStatus, ['refunded', 'failed', 'failed-api', 'cancelled', 'cancelled-api'], true) => 'fail',
            $ambiguous, in_array($upstreamStatus, ['initiated-api', 'processing-api', 'queued-api', 'pending', 'on-hold'], true) => 'pending',
            $httpStatus >= 400 && $httpStatus < 500 => 'fail',
            default => 'pending',
        };
        $electricityToken = $service === 'electricity' ? $this->electricityToken($response) : null;

        return [
            'provider' => $this->providerName,
            'transaction_type' => match ($service) {
                'airtime' => 'airtime_recharge',
                'cable' => 'cable_subscription',
                'electricity' => 'electric_bill',
                default => 'data_subscription',
            },
            'status' => $status,
            // Keep Vendify's reference as the local callback/idempotency key.
            'transaction_reference' => $response['tx_ref'] ?? null,
            'payment_reference' => $data['request_id'] ?? $response['request_id'] ?? null,
            'response_message' => $response['message'] ?? 'VTU.ng order submitted.',
            'account_or_phone' => in_array($service, ['electricity', 'cable'], true)
                ? ($data['customer_id'] ?? $response['meter_number'] ?? null)
                : ($data['phone'] ?? $response['phone'] ?? null),
            'receiver' => in_array($service, ['electricity', 'cable'], true)
                ? ($data['customer_id'] ?? $response['meter_number'] ?? null)
                : ($data['phone'] ?? $response['phone'] ?? null),
            'amount' => $response['amount'] ?? 0,
            'discount_amount' => $response['discount_amount'] ?? 0,
            'service_fee' => (float) ($response['service_fee'] ?? 0),
            'plan_type' => match ($service) {
                'airtime' => $response['network_type'] ?? 'VTU',
                'cable' => $response['subscription_type'] ?? 'change',
                'electricity' => $response['meter_type'] ?? $data['variation_id'] ?? null,
                default => $response['plan_type'] ?? 'DATA',
            },
            'token' => $electricityToken,
            'provider_status' => $upstreamStatus ?: ($ambiguous ? 'transport-ambiguous' : (string) $httpStatus),
            'safe_to_retry' => ! $ambiguous && (
                in_array($upstreamStatus, ['refunded', 'failed', 'failed-api', 'cancelled', 'cancelled-api'], true)
                || in_array($httpStatus, [401, 402, 422], true)
            ),
            'raw_payload' => [
                'provider_status' => $upstreamStatus ?: null,
                'request_id' => $data['request_id'] ?? $response['request_id'] ?? null,
                'provider_reference' => $data['order_id'] ?? $response['order_id'] ?? null,
                'meter_number' => $service === 'electricity' ? ($data['customer_id'] ?? $response['meter_number'] ?? null) : null,
                'meter_type' => $service === 'electricity' ? ($response['meter_type'] ?? $data['variation_id'] ?? null) : null,
                'customer_name' => $service === 'electricity' ? ($data['customer_name'] ?? null) : null,
                'distribution_company' => $service === 'electricity' ? ($data['service_name'] ?? $response['disco'] ?? null) : null,
            ],
            'completed_at' => $status === 'success' ? now() : null,
        ];
    }

    public function verifyTransaction(string $tx_ref): array
    {
        $requestId = str_starts_with($tx_ref, 'vendify_') ? $tx_ref : substr('vendify_'.$tx_ref, 0, 50);
        try {
            $response = $this->authenticatedPost($this->baseUrl().'/requery', ['request_id' => $requestId]);
            return $response->json() ?: [];
        } catch (ConnectionException) {
            return [];
        }
    }

    public function requeryOrder(string $requestId): array
    {
        return $this->verifyTransaction($requestId);
    }

    protected function onPendingTransaction(array $transaction): void
    {
        if (! empty($transaction['id'])) {
            ReconcileVTUNgTransaction::dispatch((int) $transaction['id'])->delay(now()->addSeconds(10));
        }
    }

    public function webhookSignatureIsValid(Request $request): bool
    {
        $pin = (string) config('services.vtu_ng.webhook_pin', '');
        $signature = (string) $request->header('X-Signature', '');

        return $pin !== '' && $signature !== '' && hash_equals(
            hash_hmac('sha256', $request->getContent(), $pin),
            $signature,
        );
    }

    protected function getPlans(?array $payload = null): array|JsonResponse
    {
        return $this->fetchRemotePlans();
    }

    public function fetchRemotePlans(): array
    {
        $response = Http::connectTimeout(5)->timeout(20)->get($this->pingEndpoint());
        if (! $response->successful() || ! is_array($response->json('data'))) {
            throw new \RuntimeException('VTU.ng plan list request failed.');
        }

        return array_values(array_filter(array_map(function ($row) {
            if (! is_array($row) || empty($row['variation_id']) || empty($row['service_id']) || empty($row['data_plan'])) {
                return null;
            }
            return [
                'external_plan_id' => (string) $row['variation_id'],
                'service_id' => strtolower((string) $row['service_id']),
                'name' => trim((string) $row['data_plan']),
                // Displayed retail price is deliberately not a Vendify cost.
                // An absent reseller_price remains zero for admin review.
                'provider_price' => (float) ($row['reseller_price'] ?? 0),
                'available' => strcasecmp((string) ($row['availability'] ?? ''), 'available') === 0,
            ];
        }, $response->json('data'))));
    }

    public function fetchRemoteCablePlans(): array
    {
        $response = Http::connectTimeout(5)->timeout(20)->get($this->baseUrl().'/variations/tv');
        if (! $response->successful() || ! is_array($response->json('data'))) {
            throw new \RuntimeException('VTU.ng cable variation list request failed.');
        }

        return array_values(array_filter(array_map(function ($row) {
            if (! is_array($row) || empty($row['variation_id']) || empty($row['service_id']) || empty($row['package_bouquet'])) {
                return null;
            }
            try {
                $serviceId = $this->cableServiceId((string) $row['service_id']);
            } catch (\InvalidArgumentException) {
                return null;
            }
            return [
                'external_plan_id' => (string) $row['variation_id'],
                'service_id' => $serviceId,
                'name' => trim((string) $row['package_bouquet']),
                'provider_price' => (float) ($row['price'] ?? 0),
                'available' => strcasecmp((string) ($row['availability'] ?? ''), 'available') === 0,
            ];
        }, $response->json('data'))));
    }

    public function syncCablePlans(): array
    {
        if (! $this->provider->active) {
            return ['fetched' => 0, 'created' => 0, 'matched' => 0, 'updated' => 0, 'conflicts' => 0, 'unavailable' => 0, 'disabled' => true];
        }

        $remotePlans = $this->fetchRemoteCablePlans();
        $summary = ['fetched' => count($remotePlans), 'created' => 0, 'matched' => 0, 'updated' => 0, 'conflicts' => 0, 'unavailable' => 0];
        $seen = [];
        foreach ($remotePlans as $remote) {
            $seen[] = $remote['external_plan_id'];
            $link = DB::table('providerables')
                ->where('provider_id', $this->provider->id)
                ->where('providerable_type', CablePlan::class)
                ->where('provider_service_id', $remote['service_id'])
                ->where('external_plan_id', $remote['external_plan_id'])
                ->first();
            $plan = $link ? CablePlan::find($link->providerable_id) : null;

            if (! $plan) {
                $normalize = fn ($value) => strtolower(trim(preg_replace('/\s+/', ' ', (string) $value) ?? ''));
                $candidates = CablePlan::query()->where('cable_network', $remote['service_id'])->get()
                    ->filter(fn (CablePlan $candidate) => $normalize($candidate->plan_name) === $normalize($remote['name']));
                if ($candidates->count() === 1) {
                    $plan = $candidates->first();
                    $summary['matched']++;
                } elseif ($candidates->count() > 1) {
                    $summary['conflicts']++;
                }
            }

            if (! $plan) {
                $plan = CablePlan::create([
                    'cable_network' => $remote['service_id'],
                    'plan_name' => $remote['name'],
                    'active' => $remote['available'],
                    'sort_order' => 0,
                    'charge_fee' => $this->defaultPricing(),
                ]);
                $summary['created']++;
            } else {
                $summary['updated']++;
            }

            DB::table('providerables')->updateOrInsert(
                [
                    'provider_id' => $this->provider->id,
                    'providerable_type' => CablePlan::class,
                    'provider_service_id' => $remote['service_id'],
                    'external_plan_id' => $remote['external_plan_id'],
                ],
                [
                    'providerable_id' => $plan->id,
                    'server_id' => $remote['external_plan_id'],
                    'provider_plan_name' => $remote['name'],
                    'provider_price' => $remote['provider_price'],
                    'cost_price' => $link->cost_price ?? $remote['provider_price'],
                    'provider_available' => $remote['available'],
                    'provider_enabled' => $link->provider_enabled ?? true,
                    'priority' => $link->priority ?? 100,
                    'last_synced_at' => now(),
                    'margin_value' => $link->margin_value ?? 0,
                    'margin_type' => $link->margin_type ?? 'fiat',
                    'created_at' => $link->created_at ?? now(),
                    'updated_at' => now(),
                ],
            );
        }

        $summary['unavailable'] = DB::table('providerables')
            ->where('provider_id', $this->provider->id)
            ->where('providerable_type', CablePlan::class)
            ->whereNotNull('external_plan_id')
            ->when($seen !== [], fn ($query) => $query->whereNotIn('external_plan_id', $seen))
            ->update(['provider_available' => false, 'last_synced_at' => now(), 'updated_at' => now()]);
        PerformanceCache::clearCatalog();

        return $summary;
    }

    public function syncPlans(): array
    {
        if (! $this->provider->active) {
            return ['fetched' => 0, 'created' => 0, 'matched' => 0, 'updated' => 0, 'skipped' => 0, 'conflicts' => 0, 'unavailable' => 0, 'disabled' => true];
        }

        $remotePlans = $this->fetchRemotePlans();
        $summary = [
            'fetched' => count($remotePlans),
            'created' => 0,
            'matched' => 0,
            'updated' => 0,
            'skipped' => 0,
            'conflicts' => 0,
            'unavailable' => 0,
        ];
        $seen = [];
        $defaultPricing = $this->defaultPricing();

        // Synced plans use VTU.NG as their plan type, so register that exact
        // key with the existing Service Routing system. Never overwrite an
        // explicit admin assignment; this only supplies the missing default.
        if (Schema::hasTable('service_routes')) {
            ServiceRoute::firstOrCreate(
                ['service_type' => 'data', 'route_key' => 'VTU.NG'],
                ['provider_id' => $this->provider->id],
            );
        }

        foreach ($remotePlans as $remote) {
            $seen[] = $remote['external_plan_id'];
            if (! preg_match('/([\d.]+)\s*(MB|GB)/i', $remote['name'], $size)) {
                $summary['skipped']++;
                continue;
            }

            $amount = rtrim(rtrim(number_format((float) $size[1], 4, '.', ''), '0'), '.');
            $unit = strtoupper($size[2]);
            preg_match('/(?:-|\bfor\b)\s*(\d+)\s*(day|days|hour|hours|week|weeks|month|months|year|years)/i', $remote['name'], $validityMatch);
            $validity = isset($validityMatch[1]) ? $validityMatch[1].' '.ucfirst(strtolower($validityMatch[2])) : '';

            $link = DB::table('providerables')
                ->where('provider_id', $this->provider->id)
                ->where('providerable_type', DataPlan::class)
                ->where('external_plan_id', $remote['external_plan_id'])
                ->first();

            $plan = $link ? DataPlan::find($link->providerable_id) : null;
            if (! $plan) {
                $candidates = DataPlan::query()
                    ->where('network', $remote['service_id'])
                    ->where('plan_name', $amount)
                    ->where('plan_size', $unit)
                    ->get();
                if ($validity !== '') {
                    $normalize = fn ($value) => strtolower(trim(preg_replace('/\s+/', ' ', (string) $value) ?? ''));
                    $candidates = $candidates->filter(fn (DataPlan $candidate) => $normalize($candidate->validity) === $normalize($validity));
                }
                if ($candidates->count() === 1) {
                    $plan = $candidates->first();
                    $summary['matched']++;
                } elseif ($candidates->count() > 1) {
                    // Ambiguous legacy rows must not be mutated or linked at
                    // random. Create a distinct, idempotent VTU.ng-backed plan
                    // and report the conflict for an admin to reconcile.
                    $summary['conflicts']++;
                }
            }

            $standardTypeId = \App\Models\NetworkType::query()
                ->whereRaw('LOWER(name) = ?', ['standard'])
                ->whereRaw('LOWER(service_type) = ?', ['data'])
                ->value('id');
            if (! $standardTypeId) {
                // Once an admin renames STANDARD, its stable foreign key is
                // discovered through this provider's existing plan mappings.
                $standardTypeId = DB::table('providerables')
                    ->join('data_plans', function ($join) {
                        $join->on('data_plans.id', '=', 'providerables.providerable_id')
                            ->where('providerables.providerable_type', DataPlan::class);
                    })
                    ->join('network_types', 'network_types.id', '=', 'data_plans.network_type_id')
                    ->where('providerables.provider_id', $this->provider->id)
                    ->whereRaw('LOWER(network_types.service_type) = ?', ['data'])
                    ->value('network_types.id');
            }

            if (! $plan) {
                $managedTypeName = $standardTypeId
                    ? \App\Models\NetworkType::whereKey($standardTypeId)->value('name')
                    : DataPlan::STANDARD_TYPE;
                $plan = DataPlan::create([
                    'network' => $remote['service_id'], 'plan_type' => $managedTypeName,
                    'network_type_id' => $standardTypeId,
                    'plan_name' => $amount, 'plan_size' => $unit, 'validity' => $validity,
                    'active' => $remote['available'], 'is_draft' => ! $remote['available'],
                    'sort_order' => 0, 'pricing' => $defaultPricing,
                    'auto_category_id' => app(\App\Services\DataPlanCategoryClassifier::class)
                        ->categoryId($remote['name'], $validity, $managedTypeName),
                ]);
                $summary['created']++;
            } else {
                // A plan first discovered while unavailable is held as a
                // draft. Publish it automatically once VTU.ng makes it
                // available; never reactivate a plan an admin later disabled.
                $updates = ['auto_category_id' => app(\App\Services\DataPlanCategoryClassifier::class)
                    ->categoryId($remote['name'], $validity, (string) $plan->plan_type)];
                if ($standardTypeId && ! $plan->network_type_id) $updates['network_type_id'] = $standardTypeId;
                $plan->update($updates);
                if ($link && $plan->is_draft && (int) $plan->network_type_id === (int) $standardTypeId && $remote['available']) {
                    $plan->update(['active' => true, 'is_draft' => false]);
                }
                $summary['updated']++;
            }

            DB::table('providerables')->updateOrInsert(
                ['provider_id' => $this->provider->id, 'external_plan_id' => $remote['external_plan_id']],
                [
                    'providerable_id' => $plan->id, 'providerable_type' => DataPlan::class,
                    'external_plan_id' => $remote['external_plan_id'], 'server_id' => $remote['external_plan_id'],
                    'provider_service_id' => $remote['service_id'], 'provider_plan_name' => $remote['name'],
                    // provider_price always follows VTU.ng. cost_price is set
                    // only on first import and remains an admin-owned override.
                    'provider_price' => $remote['provider_price'],
                    'cost_price' => $link->cost_price ?? $remote['provider_price'],
                    'provider_available' => $remote['available'],
                    'provider_enabled' => $link->provider_enabled ?? true,
                    // VTU.ng is primary for data; existing mappings retain
                    // their records and remain available as manual fallbacks.
                    'priority' => $link->priority ?? 1,
                    'last_synced_at' => now(), 'margin_value' => $link->margin_value ?? 0,
                    'margin_type' => $link->margin_type ?? 'fiat', 'updated_at' => now(),
                    'created_at' => $link->created_at ?? now(),
                ]
            );
        }

        // A missing upstream variation is retained for history, but marked
        // unavailable. This query is strictly scoped to the VTU.ng provider.
        $missing = DB::table('providerables')
            ->where('provider_id', $this->provider->id)
            ->where('providerable_type', DataPlan::class)
            ->whereNotNull('external_plan_id')
            ->when($seen !== [], fn ($query) => $query->whereNotIn('external_plan_id', $seen))
            ->update(['provider_available' => false, 'last_synced_at' => now(), 'updated_at' => now()]);
        $summary['unavailable'] = $missing;

        PerformanceCache::clearCatalog();
        return $summary;
    }

    /** New VTU.ng plans sell at cost until an admin adds role markups. */
    private function defaultPricing(): array
    {
        $roles = Role::query()->pluck('name')->filter()->all();
        $pricing = ['user' => ['type' => 'fiat', 'value' => 0]];
        foreach ($roles as $role) {
            $pricing[$role] = ['type' => 'fiat', 'value' => 0];
        }

        return $pricing;
    }

    public function verifyUser(string $service, string $identifier, array $payload): JsonResponse
    {
        if ($service === 'cable') {
            try {
                $serviceId = $this->cableServiceId((string) ($payload['cable_network'] ?? $payload['service_id'] ?? ''));
                if ($serviceId === 'showmax') {
                    return $this->success([
                        'verified' => true,
                        'service' => $serviceId,
                        'smartcard_number' => $identifier,
                        'verification_required' => false,
                    ], 'Customer verification is not required for Showmax.', 200);
                }

                $response = $this->authenticatedPost($this->baseUrl().'/verify-customer', [
                    'customer_id' => $identifier,
                    'service_id' => $serviceId,
                ]);
                $body = $response->json();
                $body = is_array($body) ? $body : [];
                $data = is_array($body['data'] ?? null) ? $body['data'] : [];
                if (! $response->successful() || strtolower((string) ($body['code'] ?? '')) !== 'success') {
                    return $this->fail([], 'Unable to verify this smartcard number.', 422);
                }

                $normalized = array_filter([
                    'verified' => true,
                    'customer_name' => $data['customer_name'] ?? $data['name'] ?? null,
                    'service' => $serviceId,
                    'smartcard_number' => $identifier,
                    'current_package' => $data['current_package'] ?? $data['current_bouquet'] ?? null,
                    'renewal_amount' => isset($data['renewal_amount']) && is_numeric($data['renewal_amount'])
                        ? (float) $data['renewal_amount'] : null,
                ], fn ($value) => $value !== null && $value !== '');
                Cache::put(
                    $this->cableVerificationKey((int) Auth::id(), $serviceId, $identifier),
                    $normalized,
                    now()->addMinutes(10),
                );

                return $this->success($normalized, 'Smartcard verification successful.', 200);
            } catch (ConnectionException) {
                return $this->fail([], 'Smartcard verification is temporarily unavailable.', 503);
            } catch (\InvalidArgumentException $e) {
                return $this->fail([], $e->getMessage(), 422);
            }
        }

        if ($service !== 'electricity') {
            return response()->json(['success' => true, 'data' => []]);
        }

        try {
            $response = $this->authenticatedPost($this->baseUrl().'/verify-customer', [
                    'customer_id' => $identifier,
                    'service_id' => $this->electricityServiceId((string) ($payload['disco'] ?? '')),
                    'variation_id' => strtolower((string) ($payload['meter_type'] ?? 'prepaid')),
                ]);
            $body = $response->json();
            $body = is_array($body) ? $body : [];
            $data = is_array($body['data'] ?? null) ? $body['data'] : [];

            if ($response->successful() && ($body['code'] ?? null) === 'success') {
                return $this->success([
                    'name' => $data['customer_name'] ?? '',
                    'address' => $data['customer_address'] ?? null,
                    'meter_number' => $data['meter_number'] ?? $identifier,
                    'minimum_amount' => $data['min_purchase_amount'] ?? null,
                    'maximum_amount' => $data['max_purchase_amount'] ?? null,
                ], 'Electricity verification successful.', 200);
            }

            return $this->fail([], $body['message'] ?? 'Meter verification failed.', $response->status());
        } catch (ConnectionException) {
            return $this->fail([], 'Meter verification is temporarily unavailable.', 503);
        } catch (\InvalidArgumentException $e) {
            return $this->fail([], $e->getMessage(), 422);
        }
    }

    private function cableServiceId(string $service): string
    {
        $key = strtolower(preg_replace('/[^a-z0-9]+/i', '', $service) ?? '');
        return match ($key) {
            'dstv' => 'dstv',
            'gotv' => 'gotv',
            'startime', 'startimes' => 'startimes',
            'showmax', 'dstvshowmax' => 'showmax',
            default => throw new \InvalidArgumentException('This cable service is not supported.'),
        };
    }

    private function cableVerificationKey(int $userId, string $service, string $identifier): string
    {
        return 'cable-verification:'.$userId.':'.$service.':'.hash('sha256', $identifier);
    }

    public static function verifiedCableCustomer(int $userId, string $service, string $identifier): ?array
    {
        $value = Cache::get('cable-verification:'.$userId.':'.$service.':'.hash('sha256', $identifier));
        return is_array($value) && ($value['verified'] ?? false) === true ? $value : null;
    }

    /** Convert Vendify's display names (and common abbreviations) to v2 IDs. */
    private function electricityServiceId(string $disco): string
    {
        $key = strtolower(preg_replace('/[^a-z0-9]+/i', '', $disco) ?? '');
        $services = [
            'ikeja-electric' => ['ikeja', 'ikedc'],
            'eko-electric' => ['eko', 'ekedc'],
            'kano-electric' => ['kano', 'kedco'],
            'portharcourt-electric' => ['portharcourt', 'phed', 'phedc'],
            'jos-electric' => ['jos', 'jed', 'jedc'],
            'ibadan-electric' => ['ibadan', 'ibedc'],
            'kaduna-electric' => ['kaduna', 'kaedco'],
            'abuja-electric' => ['abuja', 'aedc'],
            'enugu-electric' => ['enugu', 'eedc'],
            'benin-electric' => ['benin', 'bedc'],
            'aba-electric' => ['aba', 'abedc'],
            'yola-electric' => ['yola', 'yedc'],
        ];

        foreach ($services as $serviceId => $aliases) {
            if ($key === str_replace('-', '', $serviceId)) {
                return $serviceId;
            }
            foreach ($aliases as $alias) {
                if (str_contains($key, $alias)) {
                    return $serviceId;
                }
            }
        }

        throw new \InvalidArgumentException("The electricity provider [{$disco}] is not supported by VTU.ng.");
    }

    public function reconcile(Transaction $transaction, ?array $response = null): bool
    {
        $response ??= $this->verifyTransaction((string) ($transaction->payment_reference ?: $transaction->transaction_reference));
        $data = is_array($response['data'] ?? null) ? $response['data'] : $response;
        $upstream = strtolower((string) ($data['status'] ?? ''));
        $status = match ($upstream) {
            'completed-api' => 'success',
            'refunded', 'failed', 'failed-api', 'cancelled', 'cancelled-api' => 'fail',
            default => 'pending',
        };

        if ($status === 'pending') {
            $this->settleCallback([
                'status' => 'pending',
                'tx_ref' => $transaction->transaction_reference,
                'payment_reference' => $transaction->payment_reference,
                'response_message' => $response['message'] ?? $data['message'] ?? 'VTU.ng order is still processing.',
            ]);
            return false;
        }

        $this->settleCallback([
            'status' => $status,
            'tx_ref' => $transaction->transaction_reference,
            'payment_reference' => $transaction->payment_reference,
            'response_message' => $response['message'] ?? null,
            'token' => $transaction->transaction_type === 'electric_bill' ? $this->electricityToken($response) : null,
            'raw_payload' => $transaction->transaction_type === 'electric_bill' ? [
                'meter_number' => $data['customer_id'] ?? null,
                'meter_type' => $data['variation_id'] ?? $transaction->plan_type,
                'customer_name' => $data['customer_name'] ?? null,
                'distribution_company' => $data['service_name'] ?? null,
            ] : null,
        ]);

        return true;
    }

    protected function callback(Request $request): array
    {
        $status = strtolower((string) $request->input('status', $request->input('data.status')));
        return [
            'status' => match ($status) {
                'completed-api' => 'success',
                'refunded', 'failed', 'failed-api', 'cancelled', 'cancelled-api' => 'fail',
                default => 'pending',
            },
            'request_id' => $request->input('request_id', $request->input('data.request_id')),
            'payment_reference' => $request->input('request_id', $request->input('data.request_id')),
            'response_message' => $request->input('message'),
            'token' => $this->electricityToken($request->all()),
            'raw_payload' => [
                'meter_number' => $request->input('customer_id', $request->input('data.customer_id')),
                'meter_type' => $request->input('variation_id', $request->input('data.variation_id')),
                'customer_name' => $request->input('customer_name', $request->input('data.customer_name')),
                'distribution_company' => $request->input('service_name', $request->input('data.service_name')),
            ],
        ];
    }

    private function electricityToken(array $response): ?string
    {
        $data = is_array($response['data'] ?? null) ? $response['data'] : $response;
        $candidate = $data['token']
            ?? $data['electricity_token']
            ?? $data['meter_token']
            ?? $data['pin']
            ?? data_get($data, 'tokens.0');

        return is_scalar($candidate) && trim((string) $candidate) !== ''
            ? (string) $candidate
            : null;
    }
}
