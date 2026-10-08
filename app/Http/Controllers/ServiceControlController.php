<?php

namespace App\Http\Controllers;

use App\HttpResponse;
use App\Classes\Payment\PaymentFactory;
use App\Models\Provider;
use App\Models\ServiceControl;
use Illuminate\Http\Request;

/**
 * @group Admin Controls
 *
 * Manage system service controls from the admin dashboard.
 * These endpoints allow toggling service availability and retrieving grouped control data.
 */

class ServiceControlController extends Controller
{
    use HttpResponse;

        /**
     * Get all active service controls
     *
     * Fetches and returns all service controls where `isDevLock` is false (0).
     * The controls are grouped by `category` and `sub_category`.
     *
     * @response 200 {
     *   "status": "success",
     *   "message": "Request was successful",
     *   "data": {
     *     "control": {
     *       "Airtime": {
     *         "MTN": [
     *           {
     *             "id": 1,
     *             "name": "MTN Airtime",
     *             "isActive": true,
     *             "isDevLock": false,
     *             "category": "Airtime",
     *             "sub_category": "MTN"
     *           }
     *         ]
     *       }
     *     }
     *   }
     * }
     * @authenticated
     */


    public function index()
    {
        // Keep the payment-gateway controls in sync with the actual configured
        // gateways (providers, category=payment) so the admin toggles
        // availability here instead of hand-seeding service_controls rows — a
        // newly added gateway shows up automatically (off by default) with no
        // DB surgery. Historical PaymentPoint spellings are matched after
        // normalising their spaces and case.
        foreach (Provider::where('category', 'payment')->get(['id', 'name']) as $gateway) {
            $name = trim((string) $gateway->name);
            if ($name === '') {
                continue;
            }
            $normalisedName = strtolower(str_replace(' ', '', $name));
            $control = ServiceControl::query()
                ->where('category', 'transaction')
                ->where('sub_category', 'payment gateway')
                ->whereRaw("LOWER(REPLACE(TRIM(name), ' ', '')) = ?", [$normalisedName])
                ->first();

            if (! $control) {
                $control = ServiceControl::create([
                    'category' => 'transaction',
                    'sub_category' => 'payment gateway',
                    'name' => $name,
                    'isActive' => false,
                    'isDevLock' => false,
                ]);
            }

            // Older seed data incorrectly dev-locked PaymentPoint, making an
            // enabled provider impossible for the runtime selector to use.
            if ($normalisedName === 'paymentpoint' && $control->isDevLock) {
                $control->update(['isDevLock' => false]);
            }
        }

        $services = ServiceControl::where("isDevLock", 0)->orderBy('category')
        ->orderBy('name') // Or 'sub_category'
        ->get()
        ->groupBy(['category', 'sub_category']);

        return $this->success(['control' => $services]);
    }

  /**
     * Store a newly created resource in storage.
     *
     * @hideFromAPIDocumentation
    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        //
    }


    /**
     * Update the active status of a service control
     *
     * Toggle the `isActive` boolean status of a service control item by ID.
     *
     * @urlParam id string required The ID of the service control. Example: 15
     * @bodyParam isActive boolean required Whether the service is active. Example: true
     *
     * @response 200 {
     *   "status": "success",
     *   "message": "Request was successful",
     *   "data": []
     * }
     *
     * @response 404 {
     *   "status": "fail",
     *   "message": "Service control not found",
     *   "data": []
     * }
     *
     * @response 422 {
     *   "message": "The given data was invalid.",
     *   "errors": {
     *     "isActive": ["The isActive field is required."]
     *   }
     * }
     *
     * @authenticated
     */
    public function show(string $id)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        $control = ServiceControl::find($id);
        if (!$control) {
            return $this->fail([], 'Service control not found.', 404);
        }
        $validated = $request->validate([
        'isActive' => 'required|boolean',
    ]);

        if ($validated['isActive'] && strtolower(trim((string) $control->name)) === 'flutterwave') {
            $provider = Provider::whereRaw('LOWER(name) = ?', ['flutterwave'])
                ->where('category', 'payment')
                ->first();
            if (!$provider || !$provider->active || empty($provider->secret_key) || empty($provider->webhook_access)) {
                return $this->fail(
                    [],
                    'Flutterwave requires an active provider, secret key, and webhook secret before it can be enabled.',
                    422,
                );
            }
        }

        $normalisedName = strtolower(str_replace(' ', '', trim((string) $control->name)));
        if ($validated['isActive'] && $normalisedName === 'paymentpoint') {
            $provider = Provider::where('category', 'payment')
                ->whereRaw("LOWER(REPLACE(TRIM(name), ' ', '')) = ?", ['paymentpoint'])
                ->first();
            $missing = $provider ? PaymentFactory::missingFundingConfiguration($provider) : ['provider'];

            if (!$provider || !$provider->active || $missing !== []) {
                return $this->fail(
                    [],
                    'PaymentPoint requires an active provider, API key, API token, Business ID, and webhook HMAC key before it can be enabled.'
                        .($missing !== [] ? ' Missing: '.implode(', ', $missing).'.' : ''),
                    422,
                );
            }
        }

        $control->update($validated);

        return $this->success([]);
    }
 /**
     * Remove the specified resource from storage.
     *
     * @hideFromAPIDocumentation

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        //
    }
}
