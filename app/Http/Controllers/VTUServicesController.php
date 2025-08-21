<?php

namespace App\Http\Controllers;

use App\Class\SerivceControl\ServiceControlService;
use App\Class\VTUServices\VTUServiceFactory;
use App\Http\Requests\ServiceRequest;
use App\Models\Discount;
use App\Models\Transaction;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
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
        if(in_array($service, ['airtime'])){
            if (($error = Discount::getAmountRangeError($validated['amount'], $validated['network_type']))) {
                return $this->fail([], $error, 422);
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
        if ($user->wallet_balance < $validated['amount']) {
            return response()->json([
                'status' => 'error',
                'message' => 'Insufficient balance to complete this transaction.',
            ], 402);
        }

        $serviceType =$service;

        $handler = VTUServiceFactory::make($serviceType, $validated['network_type'] ?? $validated['plan_type']);

        if (!$handler) {
            return response()->json([
                'status' => 'error',
                'message' => 'Unsupported or unconfigured service.',
            ], 400);
        }

        try {
            // Log::info($validated);
            return  $handler->process($service, $validated);
        } catch (\Throwable $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to process VTU request.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get plans for data, cable, exam, etc.
     *
     * @group VTU Services
     *
     * @authenticated
     *
     * @urlParam service string required The service type (e.g. data, cable). Example: data
     *
     * @response 200 {
     *   "plans": [...]
     * }
     */

    public function plan(Request $request, string $service){
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
        $val = [];
        if($service == "cable"){
            $val = [
            'cable_network' => 'required|string',
            ];
        }elseif ($service == 'bill') {
            $val = [
            'meter_type' => 'required|string',

            ];
        }
        $payload = $request->validate(array_merge([
            'identifier' => 'required|string',
        ], $val));
        $handler = VTUServiceFactory::make($service, $request->cable_network??"");
        return $handler->verifyUser($service, $request->identifier, $payload);
    }
}
