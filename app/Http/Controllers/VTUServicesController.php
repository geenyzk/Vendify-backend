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
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
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

    private function makeRequest($method, $endpoint, $data = [])
    {
        $response = Http::withHeaders([
            'Authorization' => 'Token ' . $this->token,
            'Content-Type' => 'application/json',
        ])->{$method}($this->baseUrl . $endpoint, $data);

        return response()->json($response->json(), $response->status());
    }

    public function getUser()
    {
        return $this->makeRequest('get', 'user/');
    }

    public function getNetworks()
    {
        return $this->makeRequest('get', 'get/network/');
    }

    public function getNetworkPlans()
    {
        return $this->makeRequest('get', 'network/');
    }

    public function getDataPlans()
    {
        return $this->makeRequest('get', 'data/');
    }

    public function getDataPlanById($id)
    {
        return $this->makeRequest('get', "data/{$id}");
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
        return $this->makeRequest('post', 'Airtime_funding/', $request->all());
    }

    public function airtimeTopup(Request $request)
    {
        return $this->makeRequest('post', 'topup/', $request->all());
    }

    public function dataPurchase(Request $request)
    {
        return $this->makeRequest('post', 'data/', $request->all());
    }

    public function cableSubscription(Request $request)
    {
        return $this->makeRequest('post', 'cablesub/', $request->all());
    }

    public function electricityPayment(Request $request)
    {
        return $this->makeRequest('post', 'billpayment/', $request->all());
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
        // Amount validation now done in ServiceRequest rules for airtime (min:50, max:5000)

        // Data and Cable are both fixed-price catalog items — the vendor
        // call derives the actual bundle/subscription delivered from the
        // plan id alone, so trusting a client-submitted amount here would
        // let it diverge from what's really being charged. Always resolve
        // the real (role-aware) price server-side and ignore whatever the
        // client sent.
        if ($service === 'data' && !empty($validated['data_plan'])) {
            error_log("data");
            $dataPlan = DataPlan::find($validated['data_plan']);
            if ($dataPlan) {
                $validated['amount'] = (float) $dataPlan->price;
            }
        }
        if ($service === 'cable' && !empty($validated['cable_plan'])) {
            $cablePlan = CablePlan::find($validated['cable_plan']);
            if ($cablePlan) {
                $validated['amount'] = (float) $cablePlan->price;
            }
        }

        $isVerifiable = ServiceControlService::verify(Auth::id(),$validated['pin']??'');
        if (!$isVerifiable) {
            return $this->fail([
                "pin" => ["Invalid pin"]
            ], "", 422);
        }
        // $validated['tx_ref'] = Transaction::generateTransactionId();

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

            $handler = VTUServiceFactory::make($serviceType, $validated['network_type'] ?? $validated['plan_type'] ?? $serviceType, $validated['network'] ?? null);

            if (!$handler) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Unsupported or unconfigured service.',
                ], 400);
            }

            try {
                Log::info(["validated" => $validated]);
                return $handler->process($service, $validated);
            } catch (\Throwable $e) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Failed to process VTU request.',
                    'error' => $e->getMessage(),
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
            $handler = VTUServiceFactory::make($service, $request->cable_network??"");
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
