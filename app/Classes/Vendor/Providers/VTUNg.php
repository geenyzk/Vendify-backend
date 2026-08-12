<?php

namespace App\Classes\Vendor\Providers;

use App\Classes\Vendor\VendorBase;
use App\Models\DataPlan;
use App\Models\Transaction;
use App\Models\Role;
use App\Models\ServiceRoute;
use App\Jobs\ReconcileVTUNgTransaction;
use App\Support\PerformanceCache;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

class VTUNg extends VendorBase
{
    protected string $providerName = 'vtu_ng';

    protected function baseUrl(): string
    {
        return rtrim($this->provider->base_url ?: 'https://vtu.ng/wp-json/api/v2', '/');
    }

    protected function rootUrl(): string
    {
        return preg_replace('#/api/v2$#', '', $this->baseUrl()) ?: 'https://vtu.ng/wp-json';
    }

    protected function endpoint(string $service): string
    {
        return match ($service) {
            'data' => '/data',
            default => throw new \InvalidArgumentException("VTU.ng does not support service [$service] in this integration."),
        };
    }

    protected function pingEndpoint(): string
    {
        return $this->baseUrl().'/variations/data';
    }

    protected function getSupportedServices(): array
    {
        return ['data'];
    }

    protected function getAuthHeaders(): array
    {
        return [
            'Authorization' => 'Bearer '.$this->token(),
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        ];
    }

    private function token(): string
    {
        // api_key may hold a deliberately managed JWT. Otherwise cache a
        // username/password login for less than VTU.ng's documented 7 days.
        if ($this->provider->api_key) {
            return (string) $this->provider->api_key;
        }

        return Cache::remember("vtu-ng:token:{$this->provider->id}", now()->addDays(6), function () {
            $response = Http::connectTimeout(5)->timeout(15)->acceptJson()->post(
                $this->rootUrl().'/jwt-auth/v1/token',
                ['username' => $this->provider->username, 'password' => $this->provider->password]
            );

            $token = $response->json('token');
            if (! $response->successful() || ! is_string($token) || $token === '') {
                throw new \RuntimeException('VTU.ng authentication failed.');
            }

            return $token;
        });
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
            $response = Http::connectTimeout(8)->timeout(45)
                ->withHeaders($this->getAuthHeaders())
                ->post($this->baseUrl().$this->endpoint($service), $payload);
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

        return [
            'provider' => $this->providerName,
            'transaction_type' => 'data_subscription',
            'status' => $status,
            // Keep Vendify's reference as the local callback/idempotency key.
            'transaction_reference' => $response['tx_ref'] ?? null,
            'payment_reference' => $data['request_id'] ?? $response['request_id'] ?? null,
            'response_message' => $response['message'] ?? 'VTU.ng order submitted.',
            'account_or_phone' => $data['phone'] ?? $response['phone'] ?? null,
            'receiver' => $data['phone'] ?? $response['phone'] ?? null,
            'amount' => $response['amount'] ?? 0,
            'discount_amount' => $response['discount_amount'] ?? 0,
            'plan_type' => $response['plan_type'] ?? 'DATA',
            'completed_at' => $status === 'success' ? now() : null,
        ];
    }

    public function verifyTransaction(string $tx_ref): array
    {
        $requestId = str_starts_with($tx_ref, 'vendify_') ? $tx_ref : substr('vendify_'.$tx_ref, 0, 50);
        try {
            $response = Http::connectTimeout(8)->timeout(30)->withHeaders($this->getAuthHeaders())
                ->post($this->baseUrl().'/requery', ['request_id' => $requestId]);
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

    public function syncPlans(): array
    {
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
            preg_match('/(?:-|\bfor\b)\s*(\d+)\s*(day|days|hour|hours|week|weeks)/i', $remote['name'], $validityMatch);
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

            if (! $plan) {
                $plan = DataPlan::create([
                    'network' => $remote['service_id'], 'plan_type' => 'VTU.NG',
                    'plan_name' => $amount, 'plan_size' => $unit, 'validity' => $validity,
                    'active' => $remote['available'], 'is_draft' => ! $remote['available'],
                    'sort_order' => 0, 'pricing' => $defaultPricing,
                ]);
                $summary['created']++;
            } else {
                // A plan first discovered while unavailable is held as a
                // draft. Publish it automatically once VTU.ng makes it
                // available; never reactivate a plan an admin later disabled.
                if ($link && $plan->is_draft && $plan->plan_type === 'VTU.NG' && $remote['available']) {
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
        return response()->json(['success' => true, 'data' => []]);
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
        ];
    }
}
