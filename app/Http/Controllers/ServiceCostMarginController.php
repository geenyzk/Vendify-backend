<?php

namespace App\Http\Controllers;

use App\Models\ServiceCostMargin;
use App\Models\Role;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class ServiceCostMarginController extends Controller
{
    /**
     * Get all service cost margins.
     */
    public function index(): JsonResponse
    {
        $margins = ServiceCostMargin::with('role')->get();

        return response()->json([
            'success' => true,
            'data' => $margins,
        ]);
    }

    /**
     * Get cost margins by role.
     */
    public function byRole($roleId): JsonResponse
    {
        $role = Role::findOrFail($roleId);
        $margins = $role->serviceCostMargins()->get();

        return response()->json([
            'success' => true,
            'data' => $margins,
        ]);
    }

    /**
     * Get cost margin for a specific service type and role.
     */
    public function getByService($roleId, $serviceType): JsonResponse
    {
        $margin = ServiceCostMargin::where('role_id', $roleId)
            ->where('service_type', $serviceType)
            ->firstOrFail();

        return response()->json([
            'success' => true,
            'data' => $margin,
        ]);
    }

    /**
     * Create a new service cost margin.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'role_id' => 'required|exists:roles,id',
            'service_type' => 'required|string',
            'cost_price' => 'required|numeric|min:0',
            'margin_profit' => 'required|numeric|min:0',
            'margin_type' => 'required|string|in:fiat,percentage',
            'is_active' => 'boolean',
        ]);

        // Check if margin already exists for this role and service
        $exists = ServiceCostMargin::where('role_id', $validated['role_id'])
            ->where('service_type', $validated['service_type'])
            ->exists();

        if ($exists) {
            return response()->json([
                'success' => false,
                'message' => 'Cost margin already exists for this role and service type',
            ], 409);
        }

        $margin = ServiceCostMargin::create($validated);

        return response()->json([
            'success' => true,
            'data' => $margin,
            'message' => 'Service cost margin created successfully',
        ], 201);
    }

    /**
     * Update a service cost margin.
     */
    public function update(Request $request, $id): JsonResponse
    {
        $margin = ServiceCostMargin::findOrFail($id);

        $validated = $request->validate([
            'service_type' => 'string',
            'cost_price' => 'numeric|min:0',
            'margin_profit' => 'numeric|min:0',
            'margin_type' => 'string|in:fiat,percentage',
            'is_active' => 'boolean',
        ]);

        // Allow updating margin_type as well
        $margin->update($validated);

        return response()->json([
            'success' => true,
            'data' => $margin,
            'message' => 'Service cost margin updated successfully',
        ]);
    }

    /**
     * Delete a service cost margin.
     */
    public function destroy($id): JsonResponse
    {
        $margin = ServiceCostMargin::findOrFail($id);
        $margin->delete();

        return response()->json([
            'success' => true,
            'message' => 'Service cost margin deleted successfully',
        ]);
    }

    /**
     * Bulk update cost margins for a role.
     */
    public function bulkUpdateByRole(Request $request, $roleId): JsonResponse
    {
        $role = Role::findOrFail($roleId);

        $validated = $request->validate([
            'margins' => 'required|array',
            'margins.*.service_type' => 'required|string',
            'margins.*.cost_price' => 'required|numeric|min:0',
            'margins.*.margin_profit' => 'required|numeric|min:0',
            'margins.*.margin_type' => 'nullable|string|in:fiat,percentage',
        ]);

        $updated = [];
        foreach ($validated['margins'] as $marginData) {
            $margin = ServiceCostMargin::updateOrCreate(
                [
                    'role_id' => $roleId,
                    'service_type' => $marginData['service_type'],
                ],
                [
                    'cost_price' => $marginData['cost_price'],
                    'margin_profit' => $marginData['margin_profit'],
                    'margin_type' => $marginData['margin_type'] ?? 'fiat',
                ]
            );
            $updated[] = $margin;
        }

        return response()->json([
            'success' => true,
            'data' => $updated,
            'message' => 'Bulk update completed successfully',
        ]);
    }
}
