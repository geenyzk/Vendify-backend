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
<<<<<<< HEAD
=======

use Illuminate\Support\Facades\Http;


>>>>>>> bbdf8dbc93811b942956ea2015f977bbc20327d4
use Illuminate\Support\Facades\Log;

class VTUServicesController extends Controller
{
<<<<<<< HEAD
    /**
     * Handle a VTU request.
=======


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
>>>>>>> bbdf8dbc93811b942956ea2015f977bbc20327d4
     *
     * @param Request $request
     * @return JsonResponse
     */
<<<<<<< HEAD
=======







>>>>>>> bbdf8dbc93811b942956ea2015f977bbc20327d4
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
