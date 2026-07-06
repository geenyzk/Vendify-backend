<?php

namespace App\Http\Controllers;

use App\HttpResponse;
use App\Models\Discount;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DiscountController extends Controller
{
    use HttpResponse;

    /**
     * List all Discounts for the admin management UI.
     */
    public function index(): JsonResponse
    {
        return $this->success(['discounts' => Discount::orderByDesc('id')->get()]);
    }

    /**
     * Show a single Discount.
     */
    public function show(Discount $discount): JsonResponse
    {
        return $this->success(['discount' => $discount]);
    }

    /**
     * Create a new Discount.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate($this->rules());

        $discount = Discount::create($validated);

        return $this->success(['discount' => $discount], 'Discount created', 201);
    }

    /**
     * Update an existing Discount.
     */
    public function update(Request $request, Discount $discount): JsonResponse
    {
        $validated = $request->validate($this->rules());

        $discount->update($validated);

        return $this->success(['discount' => $discount->fresh()], 'Discount updated');
    }

    /**
     * Delete a Discount.
     */
    public function destroy(Discount $discount): JsonResponse
    {
        $discount->delete();

        return $this->success(null, 'Discount deleted');
    }

    private function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            // Matches the real $service route segment VTUServicesController
            // dispatches on (see VTUServiceFactory) — not Transaction's own
            // longer transaction_type strings.
            'service_type' => 'required|in:airtime,data,cable,electricity,exam,airtimeToCash',
            'network' => 'nullable|string|max:255',
            'discount_type' => 'required|in:percentage,fixed',
            'value' => 'required|numeric|min:0',
            'min' => 'nullable|numeric|min:0',
            'max' => 'nullable|numeric|min:0',
            'active' => 'sometimes|boolean',
            'starts_at' => 'nullable|date',
            'ends_at' => 'nullable|date|after_or_equal:starts_at',
        ];
    }
}
