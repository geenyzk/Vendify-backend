<?php

namespace App\Classes\Vendor;

use App\Classes\TemplateParser;
use App\Classes\TransactionService;
use App\Classes\Vendor\Interface\VendorInterface;
use App\HttpResponse;
use App\Models\Message;
use App\Models\Transaction;
use App\Models\Vendor;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\Request;

abstract class VendorBase implements VendorInterface
{
    use HttpResponse;
    protected string $providerName;
    protected bool $isSandbox = false;

    protected Vendor $provider;

    protected array $networkIDs = [
        'mtn' => 1,
        'airtel' => 2,
        'glo' => 3,
        '9mobile' => 4,
    ];

    protected array $cableNetworkIDs = [
        'gotv' => 1,
        'dstv' => 2,
        'startime' => 3,
    ];

    public function __construct(Vendor $provider)
    {
        $this->provider = $provider;
    }
     public function process(string $service, array $payload):JsonResponse
    {

        try {
            $formattedPayload = $this->formatPayload($service, $payload);
             if ($this->isSandbox) {
                return $this->success([]);
            }
            $parser = TemplateParser::make();
            $response = $this->sendRequest($service, $formattedPayload);
            // Merge API response with original payload to preserve discount/promotion data
            $formattedResponse = $this->formatResponse($service, array_merge($response['data'] ?? $response, $payload));
            $transaction = TransactionService::process($formattedResponse, Auth::user());
            $message = Message::wherePurpose($service . "_" . $transaction['status'])->first();
            $parsedMessage = $parser->with(["transaction" =>$transaction])->parse($message?->body ?? "");
            $responseMessage = $parsedMessage ?? $response['data']['msg'] ?? $response['message'] ?? $response['response_message'];
            
            // Log transaction details
            Log::info("Transaction Completed", [
                'transaction_id' => $transaction['id'] ?? null,
                'amount' => $transaction['amount'] ?? null,
                'discount_applied' => $transaction['discount_applied'] ?? null,
                'status' => $transaction['status'] ?? null
            ]);
            
            // Return success response with full transaction details
            if ($transaction['status'] === "success") {
                return $this->success($transaction, $responseMessage, 200);
            } else {
                return $this->fail([], $responseMessage, 500);
            }
        } catch (\Exception $e) {
            Log::error("Transaction Error", [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return $this->fail([], $e->getMessage(), 500);
        }
    }

    abstract public function sendRequest(string $service, array $payload): array;


    abstract public function checkBalance(): string;

    abstract public function verifyTransaction(string $tx_ref): array;

    abstract protected function getAuthHeaders(): array;
    abstract public function verifyUser(string $service, string $identifier, array $payload): JsonResponse;
    abstract protected function formatResponse(string $service,array $payload): array;

    public function supportsService(string $service): bool
    {
        return in_array($service, $this->getSupportedServices());
    }

    public function sandbox(): static
    {
        $this->isSandbox = true;
        return $this;
    }

    public function isHealthy(): bool
    {
        try {
            $response = $this->login();
            return $response['status'] === 'success';

        } catch (\Throwable $e) {
            Log::warning("Vendor [{$this->providerName}] is unhealthy.");
            return false;
        }
    }

    function plans(?array $payload=null): mixed
    {

        return $this->getPlans($payload);
    }

    abstract protected function getSupportedServices(): array;
    abstract protected function getPlans(?array $payload=null): array|JsonResponse;
    abstract protected function callback(Request $request):array;

    abstract protected function pingEndpoint(): string;
    abstract protected function endpoint(string $service): string;

    protected function convertDataPlanToGB(string $dataplan): float {
    $dataplan = strtoupper(trim($dataplan));

    // Match value and unit using regex (e.g., "500MB", "1.5GB")
    if (preg_match('/([\d\.]+)\s*(MB|GB)/', $dataplan, $matches)) {
        $value = (float) $matches[1];
        $unit = $matches[2];

        if ($unit === 'MB') {
            return round($value / 1024, 3); // Convert MB to GB
        }

        if ($unit === 'GB') {
            return round($value, 3);
        }
    }

    return 0.0; // fallback if parsing fails
}

    public function webhook(Request $request):void{
        $callback = $this->callback($request);
        Transaction::where("transaction_reference", $callback['tx_ref'])
        ->update($callback);
    }
}
