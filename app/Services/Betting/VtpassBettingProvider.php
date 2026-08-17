<?php

namespace App\Services\Betting;

use App\Contracts\BettingProviderInterface;
use App\Models\BettingProvider;
use App\Models\Vendor;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class VtpassBettingProvider implements BettingProviderInterface
{
    public function __construct(private readonly Vendor $vendor)
    {
    }

    public function verifyCustomer(BettingProvider $provider, string $customerId): array
    {
        try {
            $response = $this->client()->post($this->url('/merchant-verify'), [
                'billersCode' => $customerId,
                'serviceID' => $provider->biller_id,
            ]);

            return $this->normalize($response->json() ?? [], true);
        } catch (ConnectionException $e) {
            return $this->transportFailure($e);
        }
    }

    public function fundAccount(BettingProvider $provider, string $customerId, float $amount, string $reference): array
    {
        // VTpass requires the first 12 characters to be the current Lagos
        // date/time. Vendify keeps its own TXN-* reference locally and uses
        // this deterministic provider reference only for pay/requery.
        $requestId = now('Africa/Lagos')->format('YmdHi') . substr(hash('sha256', $reference), 0, 16);
        try {
            $response = $this->client()->post($this->url('/pay'), [
                'request_id' => $requestId,
                'serviceID' => $provider->biller_id,
                'billersCode' => $customerId,
                'amount' => $amount,
                'phone' => $customerId,
            ]);

            $result = $this->normalize($response->json() ?? [], false);
            $result['provider_reference'] ??= $requestId;

            return $result;
        } catch (ConnectionException $e) {
            // A timeout may happen after VTpass accepted the request. Keep the
            // reservation pending and reconcile by the same request_id.
            return [
                'status' => 'pending',
                'internal_status' => 'provider_unavailable',
                'message' => 'The request is still being confirmed.',
                'provider_reference' => $requestId,
                'raw' => ['exception' => class_basename($e)],
            ];
        }
    }

    public function supportedBillers(): array
    {
        try {
            $response = $this->client()->get($this->url('/services'), [
                'identifier' => config('betting.category_identifier', 'other-services'),
            ]);
            $items = $response->json('content', []);

            return collect(is_array($items) ? $items : [])
                ->filter(fn ($item) => is_array($item)
                    && preg_match('/bet|gaming|wager/i', (string) (($item['name'] ?? '') . ' ' . ($item['serviceID'] ?? ''))))
                ->map(fn ($item) => [
                    'name' => (string) ($item['name'] ?? $item['serviceID']),
                    'biller_id' => (string) $item['serviceID'],
                    'minimum_amount' => (float) ($item['minimium_amount'] ?? $item['minimum_amount'] ?? 100),
                    'maximum_amount' => (float) ($item['maximum_amount'] ?? 100000),
                    'metadata' => $item,
                ])->values()->all();
        } catch (ConnectionException) {
            return [];
        }
    }

    private function client()
    {
        return Http::connectTimeout(config('betting.connect_timeout', 5))
            ->timeout(config('betting.timeout', 20))
            ->acceptJson()
            ->withHeaders([
                'api-key' => $this->vendor->api_key,
                'public-key' => $this->vendor->public_key,
            ]);
    }

    private function url(string $path): string
    {
        return rtrim((string) $this->vendor->base_url, '/') . $path;
    }

    private function normalize(array $body, bool $verification): array
    {
        $code = (string) ($body['code'] ?? $body['response_description'] ?? '');
        $message = trim((string) ($body['response_description'] ?? $body['message'] ?? data_get($body, 'content.error') ?? ''));
        $transactionStatus = strtolower((string) data_get($body, 'content.transactions.status', ''));
        $permissionDenied = preg_match('/not allowed to vend|not permitted|permission denied|biller.*not enabled/i', $message);
        $pending = in_array($code, ['089', '099', '001', 'PENDING'], true)
            || in_array($transactionStatus, ['pending', 'initiated'], true)
            || preg_match('/pending|processing|initiated/i', $message);
        $wrongCustomer = (bool) data_get($body, 'content.WrongBillersCode', false)
            || preg_match('/invalid.*(customer|account|biller)|customer.*not found|wrong biller/i', $message);

        if ($permissionDenied) {
            $internal = 'provider_permission_denied';
            $status = 'failed';
        } elseif ($pending) {
            $internal = 'pending';
            $status = 'pending';
        } elseif ($code === '000' && ! in_array($transactionStatus, ['failed', 'reversed'], true)) {
            $internal = 'success';
            $status = 'success';
        } elseif ($code === '012') {
            $internal = 'unsupported_biller';
            $status = 'failed';
        } elseif (in_array($code, ['034', '035', '083', '087'], true)) {
            $internal = 'provider_unavailable';
            $status = 'failed';
        } elseif ($wrongCustomer) {
            $internal = 'customer_not_found';
            $status = 'failed';
        } else {
            $internal = $verification ? 'customer_not_found' : 'failed';
            $status = 'failed';
        }

        return [
            'status' => $status,
            'internal_status' => $internal,
            'message' => $message,
            'customer_name' => data_get($body, 'content.Customer_Name') ?? data_get($body, 'content.customer_name'),
            'provider_reference' => $body['transactionId'] ?? $body['transactionID'] ?? data_get($body, 'content.transactions.transactionId'),
            'cost' => data_get($body, 'content.transactions.total_amount'),
            'raw' => $body,
        ];
    }

    private function transportFailure(ConnectionException $e): array
    {
        return [
            'status' => 'failed',
            'internal_status' => 'provider_unavailable',
            'message' => 'Provider unavailable.',
            'provider_reference' => null,
            'raw' => ['exception' => class_basename($e)],
        ];
    }
}
