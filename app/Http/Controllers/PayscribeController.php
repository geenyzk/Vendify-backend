<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Services\PayscribeService;

class PayscribeController extends Controller
{
    protected $payscribe;

    public function __construct(PayscribeService $payscribe)
    {
        $this->payscribe = $payscribe;
    }

    // ==============================
    // 🔹 Airtime
    // ==============================
    public function purchaseAirtime(Request $request)
    {
        $data = $request->validate([
            'recipient' => 'required|string',
            'network'   => 'required|string',
            'amount'    => 'required|numeric',
            'ported'    => 'boolean',
            'ref'       => 'required|string',
            'pin'       => 'required|string',
        ]);

        if ($pinFailure = $this->transactionPinFailure($request)) {
            return $pinFailure;
        }

        return response()->json(
            $this->payscribe->purchaseAirtime(
                $data['recipient'],
                $data['network'],
                $data['amount'],
                $data['ported'] ?? false,
                $data['ref']
            )
        );
    }

    // ==============================
    // 🔹 Data
    // ==============================
    public function dataLookup($network)
    {
        return response()->json($this->payscribe->dataLookup($network));
    }

    public function purchaseData(Request $request)
    {
        $data = $request->validate([
            'plan'      => 'required|string',
            'recipient' => 'required|string',
            'network'   => 'required|string',
            'ref'       => 'required|string',
            'pin'       => 'required|string',
        ]);

        if ($pinFailure = $this->transactionPinFailure($request)) {
            return $pinFailure;
        }

        return response()->json(
            $this->payscribe->purchaseData($data['plan'], $data['recipient'], $data['network'], $data['ref'])
        );
    }

    // ==============================
    // 🔹 ePins
    // ==============================
    public function availableEPins()
    {
        return response()->json($this->payscribe->availableEPins());
    }

    public function purchasePin(Request $request)
    {
        $data = $request->validate([
            'qty' => 'required|integer|min:1',
            'id'  => 'required|string',
            'ref' => 'required|string',
            'pin' => 'required|string',
        ]);

        if ($pinFailure = $this->transactionPinFailure($request)) {
            return $pinFailure;
        }

        return response()->json($this->payscribe->purchasePin($data['qty'], $data['id'], $data['ref']));
    }

    public function jambUserLookup(Request $request)
    {
        $data = $request->validate([
            'id'      => 'required|string',
            'account' => 'required|string',
        ]);

        return response()->json($this->payscribe->jambUserLookup($data['id'], $data['account']));
    }

    public function retrieveEPin($trans_id)
    {
        return response()->json($this->payscribe->retrieveEPin($trans_id));
    }

    // ==============================
    // 🔹 Cable TV
    // ==============================
    public function fetchBouquets($service)
    {
        return response()->json($this->payscribe->fetchBouquets($service));
    }

    public function validateSmartCard(Request $request)
    {
        $data = $request->validate([
            'service' => 'required|string',
            'account' => 'required|string',
            'month'   => 'required|integer',
            'plan_id' => 'required|string',
        ]);

        return response()->json(
            $this->payscribe->validateSmartCard($data['service'], $data['account'], $data['month'], $data['plan_id'])
        );
    }

    public function payCableTv(Request $request)
    {
        $data = $request->validate([
            'plan_id'       => 'required|string',
            'customer_name' => 'required|string',
            'account'       => 'required|string',
            'service'       => 'required|string',
            'ref'           => 'required|string',
            'phone'         => 'required|string',
            'email'         => 'required|email',
            'month'         => 'required|integer',
            'pin'           => 'required|string',
        ]);

        if ($pinFailure = $this->transactionPinFailure($request)) {
            return $pinFailure;
        }

        return response()->json(
            $this->payscribe->payCableTv(
                $data['plan_id'],
                $data['customer_name'],
                $data['account'],
                $data['service'],
                $data['ref'],
                $data['phone'],
                $data['email'],
                $data['month']
            )
        );
    }

    public function topUpTv(Request $request)
    {
        $data = $request->validate([
            'plan_id'       => 'required|string',
            'customer_name' => 'required|string',
            'account'       => 'required|string',
            'service'       => 'required|string',
            'ref'           => 'required|string',
            'phone'         => 'required|string',
            'email'         => 'required|email',
            'month'         => 'required|integer',
            'pin'           => 'required|string',
        ]);

        if ($pinFailure = $this->transactionPinFailure($request)) {
            return $pinFailure;
        }

        return response()->json(
            $this->payscribe->topUpTv(
                $data['plan_id'],
                $data['customer_name'],
                $data['account'],
                $data['service'],
                $data['ref'],
                $data['phone'],
                $data['email'],
                $data['month']
            )
        );
    }

    // ==============================
    // 🔹 Internet
    // ==============================
    public function listInternetServices(Request $request)
    {
        $data = $request->validate([
            'type'    => 'required|string',
            'account' => 'required|string',
        ]);

        return response()->json($this->payscribe->listInternetServices($data['type'], $data['account']));
    }

    public function getSpectranetPinPlans()
    {
        return response()->json($this->payscribe->getSpectranetPinPlans());
    }

    public function purchaseSpectranetPins(Request $request)
    {
        $data = $request->validate([
            'plan_id' => 'required|string',
            'qty'     => 'required|integer|min:1',
            'ref'     => 'required|string',
            'pin'     => 'required|string',
        ]);

        if ($pinFailure = $this->transactionPinFailure($request)) {
            return $pinFailure;
        }

        return response()->json($this->payscribe->purchaseSpectranetPins($data['plan_id'], $data['qty'], $data['ref']));
    }

    // ==============================
    // 🔹 Electricity
    // ==============================
    public function validateElectricity(Request $request)
    {
        $data = $request->validate([
            'number'  => 'required|string',
            'type'    => 'required|string',
            'amount'  => 'required|numeric',
            'service' => 'required|string',
        ]);

        return response()->json(
            $this->payscribe->validateElectricity($data['number'], $data['type'], $data['amount'], $data['service'])
        );
    }

    public function electricityPayment(Request $request)
    {
        $data = $request->validate([
            'number'        => 'required|string',
            'type'          => 'required|string',
            'amount'        => 'required|numeric',
            'service'       => 'required|string',
            'phone'         => 'required|string',
            'customer_name' => 'required|string',
            'ref'           => 'required|string',
            'pin'           => 'required|string',
        ]);

        if ($pinFailure = $this->transactionPinFailure($request)) {
            return $pinFailure;
        }

        return response()->json(
            $this->payscribe->electricityPayment(
                $data['number'],
                $data['type'],
                $data['amount'],
                $data['service'],
                $data['phone'],
                $data['customer_name'],
                $data['ref']
            )
        );
    }

    // ==============================
    // 🔹 Requery
    // ==============================
    public function requeryTransaction($trans_id)
    {
        return response()->json($this->payscribe->requeryTransaction($trans_id));
    }
}
