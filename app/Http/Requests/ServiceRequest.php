<?php

namespace App\Http\Requests;

use App\Rules\ValidPhoneForNetwork;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Log;

class ServiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $service = $this->route('service'); // e.g., 'airtime', 'data', 'exam'
        $network = $this->input('network');
        $bypass = filter_var($this->input('bypass'), FILTER_VALIDATE_BOOLEAN);

        if (in_array($service, ['recharge_card', "data_card"])) {
            return $this->airtimeRechargeRules();
        }

        // Base rules for other services (airtime, data, etc.)
        $rules = $this->baseRules();


        if ($service === 'airtime' && !empty($network)) {
            $rules['network_type'] = 'required|string|in:vtu,sns,sme,gifting,cooperate-gifting';
        }

        $rules = array_merge($rules, $this->ruleMaker($service, $bypass));
        return $rules;

    }


    private function airtimeRechargeRules(): array
    {
        return [
            'network'   => 'required|string|in:mtn,airtel,glo,9mobile',
            "plan_type" => "|required|exists:airtime_pin_plans,id",
            'tx_ref'    => 'required|unique:transactions,transaction_reference',
            'pin'       => 'sometimes|nullable',
            'card_name'  => 'required|string',
            'quantity'  => 'required|integer|min:1',
            'amount'    => 'required|numeric|min:1',
        ];
    }

    /**
     * Base rules for other services
     */
    private function baseRules(): array
    {
        return [
            'amount'           => 'required|numeric|min:1',
            'bypass'           => 'required|boolean',
            'pin'              => 'sometimes|nullable',
            'code'             => 'sometimes|nullable|string|max:50',
            'product'          => 'sometimes|nullable|string',
            'discount_amount'  => 'sometimes|numeric',
            'simulate_status'  => 'sometimes|string',
            'tx_ref'           => 'required|unique:transactions,transaction_reference',
        ];
    }

    private function ruleMaker(string $service, bool $bypass = false) {
        switch($service){
            case "data":
                return [
                    'network' => 'required|string|in:mtn,airtel,glo,9mobile',
                    'plan_type' => 'required|string|in:sme,gifting,cooperate gifting',
                    'data_plan' => 'required|exists:data_plans,id',
                    "phone" => ['required', 'string', 'regex:/^\+?[0-9]{10,15}$/']
                ];
            case "airtime":
                $rules = [
                    'network' => 'required|string|in:mtn,airtel,glo,9mobile',
                    'amount' => 'required|numeric|min:50|max:5000',
                ];
                $phoneRules = ['required', 'string', 'regex:/^\+?[0-9]{10,15}$/'];
                if (!empty($network) && !$bypass) {
                    $phoneRules[] = new ValidPhoneForNetwork($network);
                }

                $rules['phone'] = $phoneRules;
                return $rules;
            case "cable":
                return [
                    "cable_network" => "required",
                    "iuc" => "required|string",
                    "cable_plan" => "required|numeric",
                ];
            case "exam":
                return [
                    'exam_id'  => 'required|exists:exam_plans,name',
                    'quantity' => 'required|integer|min:1',
                    'tx_ref'   => 'required|unique:transactions,transaction_reference',
                    'pin'      => 'sometimes|nullable',
                    'amount'   => 'required|numeric|min:1',
                ];
            case "artime_recharge":
                return [];

        }
    }

}
