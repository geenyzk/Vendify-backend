<?php

namespace App\Http\Requests;

use App\Models\AirtimePlan;
use App\Models\BillPlan;
use App\Models\CablePlan;
use App\Models\DataPlan;
use App\Rules\ValidPhoneForNetwork;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

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

        $rules = array_merge($rules, $this->ruleMaker($service, $bypass, $network));
        return $rules;

    }


    private function airtimeRechargeRules(): array
    {
        return [
            'network'   => 'required|string|in:mtn,airtel,glo,9mobile',
            "plan_type" => "|required|exists:airtime_pin_plans,id",
            'tx_ref'    => 'required|unique:transactions,transaction_reference',
            'pin'       => 'required|string',
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
            'pin'              => 'required|string',
            'code'             => 'sometimes|nullable|string|max:50',
            'product'          => 'sometimes|nullable|string',
            'discount_amount'  => 'sometimes|numeric',
            'simulate_status'  => 'sometimes|string',
            'tx_ref'           => ['required', 'string', 'max:100', 'regex:/^[A-Za-z0-9_-]+$/'],
        ];
    }

    private function ruleMaker(string $service, bool $bypass = false, ?string $network = null) {
        switch($service){
            case "data":
                // plan_type is provider/admin-defined free text, not a fixed
                // enum: vendor sync stores uppercase codes (e.g. "SME",
                // "GIFTING", "CG" — see Ogdams::syncPlans / SMEPlug / Adex) and
                // the admin plan form lets you type your own. Pinning it to a
                // fixed lowercase list here rejected every synced plan with
                // "The selected plan type is invalid". The closure on data_plan
                // below is the real guard — the submitted plan_type must equal
                // the chosen plan's actual plan_type — so a free string here is
                // both correct and sufficient.
                return [
                    'network' => 'required|string|in:mtn,airtel,glo,9mobile',
                    'plan_type' => 'required|string',
                    'data_plan' => [
                        'required',
                        'exists:data_plans,id',
                        function ($attribute, $value, $fail) use ($network) {
                            $plan = DataPlan::find($value);
                            if (!$plan || !$plan->active) {
                                $fail('This data plan is currently unavailable.');
                                return;
                            }
                            if ($network && strtolower($plan->network) !== strtolower($network)) {
                                $fail('This data plan does not belong to the selected network.');
                                return;
                            }
                            $planType = $this->input('plan_type');
                            if ($planType && $plan->plan_type !== $planType) {
                                $fail('This data plan does not belong to the selected plan type.');
                            }
                        },
                    ],
                    "phone" => ['required', 'string', 'regex:/^\+?[0-9]{10,15}$/'],
                ];
            case "airtime":
                // The admin-configured Airtime Plan (Products > Airtime &
                // Data) is the sole source of truth for both whether a
                // network can be purchased at all AND which plan
                // types/categories (vtu, sns, ...) it currently offers — a
                // network can have several active rows, one per category,
                // each with its own min/max (e.g. 9mobile is capped lower).
                $activePlans = $network
                    ? AirtimePlan::where('name', $network)->where('active', true)->get()
                    : collect();
                $hasActivePlan = $activePlans->isNotEmpty();
                // A plan with no category set (nullable) still needs a
                // valid enum value to submit — normalize it to "vtu".
                $categories = $activePlans->map(fn ($p) => $p->category ?: 'vtu')->unique()->values()->all();
                if (empty($categories)) {
                    $categories = ['vtu'];
                }

                $networkType = $this->input('network_type');
                $plan = $activePlans->first(fn ($p) => ($p->category ?: 'vtu') === $networkType) ?? $activePlans->first();

                $rules = [
                    'network' => [
                        'required', 'string', 'in:mtn,airtel,glo,9mobile',
                        function ($attribute, $value, $fail) use ($hasActivePlan, $network) {
                            if ($network && !$hasActivePlan) {
                                $fail('Airtime purchases are currently unavailable for this network.');
                            }
                        },
                    ],
                    'network_type' => ['required', 'string', Rule::in($categories)],
                    'amount' => 'required|numeric|min:' . ($plan->min ?? 50) . '|max:' . ($plan->max ?? 5000),
                ];
                $phoneRules = ['required', 'string', 'regex:/^\+?[0-9]{10,15}$/'];
                if (!empty($network) && !$bypass) {
                    $phoneRules[] = new ValidPhoneForNetwork($network);
                }

                $rules['phone'] = $phoneRules;
                return $rules;
            case "cable":
                // Cable's own network field is `cable_network`, not the
                // `network` used by airtime/data — read it directly rather
                // than relying on the generic $network passed in.
                $cableNetwork = $this->input('cable_network');
                return [
                    "cable_network" => "required|string",
                    "iuc" => "required|string",
                    "cable_plan" => [
                        'required',
                        'exists:cable_plans,id',
                        function ($attribute, $value, $fail) use ($cableNetwork) {
                            $plan = CablePlan::find($value);
                            if (!$plan || !$plan->active) {
                                $fail('This cable plan is currently unavailable.');
                                return;
                            }
                            if ($cableNetwork && strtolower($plan->cable_network) !== strtolower($cableNetwork)) {
                                $fail('This cable plan does not belong to the selected provider.');
                            }
                        },
                    ],
                ];
            case "electricity":
                // Bill Plan (Products > Bill) is the Airtime Plan equivalent
                // for electricity: a per-disco amount range, whether the
                // disco is currently purchasable at all, and its own
                // service fee — electricity has no fixed catalog price, so
                // there's no plan id to validate against, just the range.
                $disco = $this->input('disco');
                $billPlan = $disco ? BillPlan::where('disco', $disco)->where('active', true)->first() : null;

                return [
                    'disco' => [
                        'required', 'string',
                        function ($attribute, $value, $fail) use ($billPlan, $disco) {
                            if ($disco && !$billPlan) {
                                $fail('Bill payments are currently unavailable for this disco.');
                            }
                        },
                    ],
                    'meter_number' => 'required|string',
                    'meter_type' => 'required|string|in:prepaid,postpaid',
                    // VTU.ng v2 documents electricity amount as an integer.
                    // Reject decimals instead of charging one value locally
                    // and silently truncating the upstream token value.
                    'amount' => 'required|integer|min:' . ($billPlan->min ?? 500) . '|max:' . ($billPlan->max ?? 100000),
                ];
            case "exam":
                return [
                    'exam_id'  => 'required|exists:exam_plans,name',
                    'quantity' => 'required|integer|min:1',
                    'tx_ref'   => 'required|unique:transactions,transaction_reference',
                    'pin'      => 'required|string',
                    'amount'   => 'required|numeric|min:1',
                ];
            case "artime_recharge":
                return [];

        }
    }

}
