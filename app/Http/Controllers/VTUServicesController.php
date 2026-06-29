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
use Illuminate\Support\Facades\Log;

class VTUServicesController extends Controller
{
    /**
     * Handle a VTU request.
     *
     * @param Request $request
     * @return JsonResponse
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
