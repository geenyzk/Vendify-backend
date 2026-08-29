<?php

namespace App\Http\Controllers;

use App\Classes\SerivceControl\ServiceControlService;
use App\Classes\VTUServices\VTUServiceFactory;
use App\Http\Requests\ServiceRequest;
use App\Models\BillPlan;
use App\Models\CablePlan;
use App\Models\DataPlan;
use App\Models\Discount;
use App\Models\Transaction;
use App\Services\PromotionService;
use App\Support\PerformanceCache;
use App\Support\VendorErrorMessage;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class VTUServicesController extends Controller
{


  private $baseUrl;
    private $token;

    public function __construct()
    {
        $this->baseUrl = env('VTU_API_BASE');
        $this->token = env('VTU_API_TOKEN');
    }

    private function performRequest($method, $endpoint, $data = [])
    {
        $response = Http::timeout(10)
            ->retry(1, 100)
            ->withHeaders([
                'Authorization' => 'Token ' . $this->token,
                'Content-Type' => 'application/json',
            ])->{$method}($this->baseUrl . $endpoint, $data);

        return [
            'body' => $response->json(),
            'status' => $response->status(),
        ];
    }

    private function makeRequest($method, $endpoint, $data = [], int $ttlMinutes = 0)
    {
        $cacheKey = 'vtu:' . strtolower($method) . ':' . $endpoint . ':' . md5(json_encode($data));

        $payload = $ttlMinutes > 0
            ? Cache::remember($cacheKey, now()->addMinutes($ttlMinutes), fn () => $this->performRequest($method, $endpoint, $data))
            : $this->performRequest($method, $endpoint, $data);

        return response()->json($payload['body'] ?? [], $payload['status'] ?? 200);
    }

    public function getUser()
    {
        return $this->makeRequest('get', 'user/', [], 1);
    }

    public function getNetworks()
    {
        return $this->makeRequest('get', 'get/network/', [], 10);
    }

    public function getNetworkPlans()
    {
        return $this->makeRequest('get', 'network/', [], 10);
    }

    public function getDataPlans()
    {
        return $this->makeRequest('get', 'data/', [], 10);
    }

    public function getDataPlanById($id)
    {
        return $this->makeRequest('get', "data/{$id}", [], 10);
    }

    public function validateIUC(Request $request)
    {
        return $this->makeRequest('get', 'validateiuc', [
            'smart_card_number' => $request->smart_card_number,
            'cablename' => $request->cablename,
        ]);
    }

    public function validateMeter(Request $request)
    {
        return $this->makeRequest('get', 'validatemeter', [
            'meternumber' => $request->meternumber,
            'disconame' => $request->disconame,
            'mtype' => $request->mtype,
        ]);
    }

    public function airtimeFunding(Request $request)
    {
        $request->validate(['pin' => 'required|string']);
        if ($pinFailure = $this->transactionPinFailure($request)) {
            return $pinFailure;
        }

        return $this->makeRequest('post', 'Airtime_funding/', $request->except('pin'));
    }

    public function airtimeTopup(Request $request)
    {
        $request->validate(['pin' => 'required|string']);
        if ($pinFailure = $this->transactionPinFailure($request)) {
            return $pinFailure;
        }

        return $this->makeRequest('post', 'topup/', $request->except('pin'));
    }

    public function dataPurchase(Request $request)
    {
        $request->validate(['pin' => 'required|string']);
        if ($pinFailure = $this->transactionPinFailure($request)) {
            return $pinFailure;
        }

        return $this->makeRequest('post', 'data/', $request->except('pin'));
    }

    public function cableSubscription(Request $request)
    {
        $request->validate(['pin' => 'required|string']);
        if ($pinFailure = $this->transactionPinFailure($request)) {
            return $pinFailure;
        }

        return $this->makeRequest('post', 'cablesub/', $request->except('pin'));
    }

    public function electricityPayment(Request $request)
    {
        $request->validate(['pin' => 'required|string']);
        if ($pinFailure = $this->transactionPinFailure($request)) {
            return $pinFailure;
        }

        return $this->makeRequest('post', 'billpayment/', $request->except('pin'));
    }


       /**
     * Handle VTU service requests like airtime, data, etc.
     *
     * @group VTU Services
     *
     * @authenticated
     *
     * Handles a VTU service request after validating pin and user balance.
     *
     * @urlParam service string required The service type (e.g. airtime, data, cable). Example: airtime
     *
     * @bodyParam network_type string required The network type. Example: mtn
     * @bodyParam amount numeric required The amount for the transaction. Example: 100
     * @bodyParam phone string required The phone number. Example: 08012345678
     * @bodyParam pin string required The user transaction pin. Example: 1234
     *
     * @response 200 {
     *    "status": "success",
     *    "message": "Transaction successful",
     *    "data": { ... }
     * }
     *
     * @response 402 {
     *    "status": "error",
     *    "message": "Insufficient balance to complete this transaction."
     * }
     *
     * @response 422 {
     *    "status": "fail",
     *    "errors": {
     *       "pin": ["Invalid pin"]
     *    }
     * }
     */


    public function handle(ServiceRequest $request, string $service): JsonResponse
    {

        $validated = $request->validated();
        if ($pinFailure = $this->transactionPinFailure($request)) {
            return $pinFailure;
        }

        // A network retry must never create a second upstream order. Return
        // the transaction already created for this user's idempotency key.
        $existing = Transaction::where('transaction_reference', $validated['tx_ref'])->first();
        if ($existing) {
            if ((int) $existing->user_id !== (int) $request->user()->id) {
                return $this->fail([], 'Invalid transaction reference.', 422);
            }

            $code = $existing->status === 'pending' ? 202 : ($existing->status === 'success' ? 200 : 422);
            return $existing->status === 'fail'
                ? $this->fail(['transaction' => $existing], $existing->response_message ?: 'This transaction failed.', $code)
                : $this->success($existing, $existing->response_message, $code, $existing->status);
        }

        // Amount validation now done in ServiceRequest rules for airtime (min:50, max:5000)

        // Data and Cable are both fixed-price catalog items — the vendor
        // call derives the actual bundle/subscription delivered from the
        // plan id alone, so trusting a client-submitted amount here would
        // let it diverge from what's really being charged. Always resolve
        // the real (role-aware) price server-side and ignore whatever the
        // client sent.
        if ($service === 'data' && !empty($validated['data_plan'])) {
            $dataPlan = DataPlan::find($validated['data_plan']);
            if ($dataPlan) {
                // price is role-aware and is null when this plan has no price
                // for the buyer's role. (float) null would be 0 — vending real
                // data for ₦0 while the vendor still charges us. Refuse instead.
                if ($dataPlan->price === null || (float) $dataPlan->price <= 0) {
                    return $this->fail([], 'This data plan is not available for your account right now. Please contact support.', 422);
                }
                $validated['amount'] = (float) $dataPlan->price;
            }
        }
        if ($service === 'cable' && !empty($validated['cable_plan'])) {
            $cablePlan = CablePlan::find($validated['cable_plan']);
            if ($cablePlan) {
                if ($cablePlan->price === null || (float) $cablePlan->price <= 0) {
                    return $this->fail([], 'This cable plan is not available for your account right now. Please contact support.', 422);
                }
                $validated['amount'] = (float) $cablePlan->price;
            }
        }

        // Keep the validated client-generated idempotency reference. Replacing
        // it here allowed two retries carrying the same key to become two
        // different upstream electricity orders.

        $user = Auth::user();

        return DB::transaction(function () use ($service, $validated, $user) {
            $originalAmount = (float) $validated['amount'];
            $totalDiscountAmount = 0;
            $promotionId = null;

            // Step 1: Apply base Discount (automatic flash-sale style price
            // cut, scoped to this service and — if set on the network's
            // discount — this exact network). Applies to every service, not
            // just airtime/data; network is simply null for services that
            // don't have one (exam, cable, electricity).
            $baseDiscountAmount = 0;
            $network = $validated['network'] ?? $validated['provider'] ?? null;

            try {
                $baseDiscountedAmount = Discount::getDiscountedAmount($originalAmount, $service, $network);
                $baseDiscountAmount = $originalAmount - $baseDiscountedAmount;
                $totalDiscountAmount += $baseDiscountAmount;

                Log::info("Base discount applied", [
                    'original_amount' => $originalAmount,
                    'base_discounted_amount' => $baseDiscountedAmount,
                    'base_discount_amount' => $baseDiscountAmount,
                    'service' => $service,
                    'network' => $network,
                    'user_type' => $user?->user_type
                ]);
            } catch (\Exception $e) {
                Log::error("Error calculating base discount: " . $e->getMessage());
                // Continue without base discount if there's an error
            }

            // Step 2: Apply promotion if code is provided (additional discount on top of base)
            $promotionDiscount = 0;
            if (!empty($validated['code'] ?? '')) {
                // Apply promotion on top of base-discounted amount
                $baseDiscountedAmount = $originalAmount - $baseDiscountAmount;

                $promotionResult = PromotionService::applyPromotion(
                    $validated['code'],
                    $baseDiscountedAmount, // Use amount after base discount
                    $validated['product'] ?? ($service === 'airtime' ? 'airtime' : $service),
                    $validated['network'] ?? $validated['provider'] ?? null,
                    $user
                );

                if (!$promotionResult['success']) {
                    // Invalid promo code
                    return response()->json([
                        'status' => 'error',
                        'message' => $promotionResult['message'],
                    ], 422);
                }

                $promotionDiscount = $promotionResult['discount_amount'];
                $promotionId = $promotionResult['promotion_id'];
                $totalDiscountAmount += $promotionDiscount;

                Log::info("Promotion discount applied", [
                    'promotion_discount' => $promotionDiscount,
                    'promotion_id' => $promotionId,
                    'base_discounted_amount' => $baseDiscountedAmount
                ]);
            }

            // Calculate final amount after all discounts
            $finalAmount = $originalAmount - $totalDiscountAmount;
            $finalAmount = max(0, $finalAmount); // Ensure no negative amounts

            // Step 2.5: Add the Bill Plan service fee, if any — the reverse
            // of a discount: the customer pays MORE than the token amount
            // requested, not less. The disco still receives $originalAmount
            // in full; only the wallet debit and the recorded transaction
            // fee reflect this.
            $serviceFeeAmount = 0;
            if ($service === 'electricity' && !empty($validated['disco'])) {
                $billPlan = BillPlan::where('disco', $validated['disco'])->where('active', true)->first();
                if ($billPlan) {
                    $serviceFeeAmount = $billPlan->resolveServiceFee($originalAmount);
                    $finalAmount += $serviceFeeAmount;
                }
            }

            Log::info("Final amount calculation", [
                'original_amount' => $originalAmount,
                'base_discount_amount' => $baseDiscountAmount,
                'promotion_discount' => $promotionDiscount,
                'total_discount_amount' => $totalDiscountAmount,
                'service_fee_amount' => $serviceFeeAmount,
                'final_amount' => $finalAmount,
                'user_balance' => $user->wallet_balance
            ]);

            // Step 3: Check balance against final amount
            if ($user->wallet_balance < $finalAmount) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Insufficient balance to complete this transaction.',
                ], 402);
            }

            // Step 4: Add all discount details to validated data for processing
            $validated['amount'] = $originalAmount;                 // Original amount for API (what to buy)
            $validated['final_amount'] = $finalAmount;              // Final amount user pays (after discounts/fees)
            $validated['promotion_id'] = $promotionId;              // Link to promotion if used
            $validated['discount_amount'] = $totalDiscountAmount;   // Total discount applied
            $validated['service_fee'] = $serviceFeeAmount;          // Bill Plan fee, on top of the amount

            $serviceType = $service;

            // The service's own routing dimension for Service Routing lookup:
            // data → plan_type, airtime → network_type/category, cable → cable
            // network, electricity → disco, and the service name for singletons
            // (exam). Kept separate from the $sub arg (which stays the legacy
            // stock-vending key) so cable/electricity route by the right value.
            $routeKey = $validated['plan_type']
                ?? $validated['network_type']
                ?? $validated['cable_network']
                ?? $validated['disco']
                ?? $serviceType;

            // $originalAmount (not final_amount) is the value actually vended,
            // which is what SIM-stock eligibility must be measured against.
            $routingSub = $validated['network_type'] ?? $validated['plan_type'] ?? $serviceType;
            $planId = $validated['data_plan'] ?? $validated['cable_plan'] ?? null;
            $handler = VTUServiceFactory::make($serviceType, $routingSub, $validated['network'] ?? null, $planId, $routeKey, (float) $originalAmount);

            if (!$handler) {
                return response()->json([
                    'status' => 'error',
                    'message' => $serviceType === 'airtime'
                        ? 'Airtime routing is not configured for this network and type.'
                        : 'Unsupported or unconfigured service.',
                ], $serviceType === 'airtime' ? 500 : 400);
            }

            try {
                // Never log the validated payload: it contains the customer's
                // transaction PIN and service identifiers. Keep only the
                // non-sensitive correlation fields needed for diagnostics.
                Log::info('Processing VTU request', [
                    'service' => $service,
                    'transaction_reference' => $validated['tx_ref'],
                ]);
                $result = $handler->process($service, $validated);

                // Only an immediate, confirmed failure is eligible for a
                // retry. Success and 202/pending responses may already have
                // delivered or queued value and must never be sent twice.
                $resultBody = $result->getData(true);
                $shouldFailOver = $result->getStatusCode() >= 500
                    && ($resultBody['success'] ?? false) === false
                    && data_get($resultBody, 'errors.safe_to_retry') === true;

                if ($shouldFailOver && in_array($serviceType, ['data', 'airtime', 'cable'], true)) {
                    $failedReference = $validated['tx_ref'];
                    $excludedProviderIds = [$handler->providerId()];

                    while (true) {
                        $fallback = VTUServiceFactory::makeFallback(
                            $serviceType,
                            $routingSub,
                            $validated['network'] ?? null,
                            $planId,
                            (float) $originalAmount,
                            $excludedProviderIds,
                        );

                        if (! $fallback) {
                            break;
                        }

                        $excludedProviderIds[] = $fallback->providerId();
                        $validated['tx_ref'] = Transaction::generateTransactionId();
                        Log::warning('VTU provider failed; trying configured fallback', [
                            'service' => $serviceType,
                            'failed_reference' => $failedReference,
                            'fallback_reference' => $validated['tx_ref'],
                            'fallback_provider_id' => $fallback->providerId(),
                            'plan_id' => $planId,
                        ]);

                        $fallbackResult = $fallback->process($service, $validated);

                        // The failed row is an internal retry artifact once
                        // another configured provider has taken over. Its
                        // funds were already refunded by VendorBase::process().
                        Transaction::where('transaction_reference', $failedReference)
                            ->where('status', 'fail')
                            ->delete();

                        $fallbackBody = $fallbackResult->getData(true);
                        $fallbackFailedImmediately = $fallbackResult->getStatusCode() >= 500
                            && ($fallbackBody['success'] ?? false) === false;
                        $fallbackSafeToRetry = $fallbackFailedImmediately
                            && data_get($fallbackBody, 'errors.safe_to_retry') === true;

                        if (! $fallbackFailedImmediately) {
                            return $fallbackResult;
                        }

                        $failedReference = $validated['tx_ref'];
                        $result = $fallbackResult;
                        if (! $fallbackSafeToRetry) {
                            break;
                        }
                    }
                }

                return $result;
            } catch (\Throwable $e) {
                Log::error('Failed to process VTU request', [
                    'exception' => get_class($e),
                    'error' => $e->getMessage(),
                ]);
                return response()->json([
                    'status' => 'error',
                    'message' => VendorErrorMessage::forCurrentUser($e->getMessage(), 'fail', false),
                ], 500);
            }
        });
    }

    /**
     * Get plans for data, cable, exam, etc.
     *
     * @group VTU Services
        } catch (\Throwable $th) {
            //throw $th;
            Log::info($th);
        } {
     *
     * @authenticated
     *
     * @urlParam service string required The service type (e.g. data, cable). Example: data
     *
     * @response 200 {
     *   "plans": [...]
     * }
     */

    /**
     * Preview the automatic Discount (if any) that would apply to a
     * purchase, without actually charging anything. Mirrors exactly what
     * handle()'s Step 1 computes, so the confirm screen can show the real
     * figure instead of guessing at it client-side.
     *
     * @group VTU Services
     * @authenticated
     *
     * @urlParam service string required The service type (e.g. airtime). Example: airtime
     * @queryParam amount numeric required The amount before any discount. Example: 1000
     * @queryParam network string The network/provider to scope the discount to. Example: mtn
     */
    public function discountPreview(Request $request, string $service): JsonResponse
    {
        $amount = (float) $request->query('amount', 0);
        $network = $request->query('network');

        if ($amount <= 0) {
            return $this->success([
                'original_amount' => 0, 'discounted_amount' => 0, 'discount_amount' => 0,
                'service_fee' => 0, 'final_amount' => 0,
            ]);
        }

        $discountedAmount = Discount::getDiscountedAmount($amount, $service, $network);
        $discountAmount = round($amount - $discountedAmount, 2);

        // Bill Plan's service fee (electricity only) is additive on top —
        // mirrors the same computation handle() does, so the confirm
        // screen shows the real total instead of guessing at it client-side.
        $serviceFee = 0;
        if ($service === 'electricity') {
            $disco = $request->query('disco');
            $billPlan = $disco ? BillPlan::where('disco', $disco)->where('active', true)->first() : null;
            if ($billPlan) {
                $serviceFee = $billPlan->resolveServiceFee($amount);
            }
        }

        return $this->success([
            'original_amount' => $amount,
            'discounted_amount' => $discountedAmount,
            'discount_amount' => $discountAmount,
            'service_fee' => $serviceFee,
            'final_amount' => round($discountedAmount + $serviceFee, 2),
        ]);
    }

    /**
     * The active discount RULE (type + value) for a service/network, so the
     * storefront can strike through and discount every listed plan price in a
     * single call instead of previewing each amount separately. Mirrors
     * Discount::findApplicable — returns null when nothing is currently live.
     */
    public function activeDiscount(Request $request, string $service): JsonResponse
    {
        $network = $request->query('network');
        $cacheKey = PerformanceCache::catalogVersionedKey('active-discount', [
            'service' => $service,
            'network' => $network,
        ]);
        $discount = Cache::remember(
            $cacheKey,
            now()->addMinutes(5),
            fn () => Discount::findApplicable($service, $network)
        );

        return $this->success([
            'discount' => $discount ? [
                'discount_type' => $discount->discount_type,
                'value' => (float) $discount->value,
            ] : null,
        ]);
    }

    public function plan(Request $request, string $service){
        Log::info($request);
        $servicePlansObject = [
            "data" => "data_plans",
            "cable" => "cable_plans",
            "exam" => "exam_plans",
            "airtime-pin" => "airtime_pin_plans",
            "data-pin" => "data_pin_plans",
        ];
       return VTUServiceFactory::make("data", "")->plans([
        "table" => $servicePlansObject[$service],
        "request" => $request
       ]);
    }

     /**
     * Verify user service info (like smartcard number, meter, etc.)
     *
     * @group VTU Services
     *
     * @authenticated
     *
     * @urlParam service string required The service type (e.g. cable, bill). Example: cable
     *
     * @bodyParam identifier string required The value to verify (e.g. smartcard or meter number). Example: 12345678901
     * @bodyParam cable_network string required If service is cable. Example: dstv
     * @bodyParam meter_type string required If service is bill. Example: prepaid
     *
     * @response 200 {
     *    "status": "success",
     *    "message": "User verified",
     *    "data": {...}
     * }
     */
    function verify(Request $request, string $service){
        try {
            $val = [];
            if($service == "cable"){
                $val = [
                'cable_network' => 'required|string',
                ];
            }elseif ($service == 'electricity') {
                $val = [
                'meter_type' => 'required|string',
                'disco' => 'required|string',
                ];
            }
            $payload = $request->validate(array_merge([
                'identifier' => 'required|string',
            ], $val));
            $routeKey = $service === 'electricity'
                ? ($payload['disco'] ?? $service)
                : ($payload['cable_network'] ?? '');
            $handler = VTUServiceFactory::make($service, $routeKey, null, null, $routeKey);
            if (! $handler) {
                return $this->fail([], 'Electricity service is not configured.', 503);
            }
            return $handler->verifyUser($service, $request->identifier, $payload);
        } catch (\Illuminate\Validation\ValidationException $e) {
            // Previously swallowed into a silent null response — the
            // frontend would see {success:true, data:null} and have no
            // idea verification actually failed validation.
            return $this->fail($e->errors(), $e->getMessage(), 422);
        } catch (\Throwable $th) {
            Log::error($th);
            return $this->fail([], 'Verification failed. Please try again.', 500);
        }

        // }Except(e){

        // }
    }
}
