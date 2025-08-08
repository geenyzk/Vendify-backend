<?php

namespace App\Http\Controllers;

use App\HttpResponse;
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
        //
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
     */
    public function store(Request $request)
    {
        //
    }

    /**
     * Display the specified resource.
     *
     * @hideFromAPIDocumentation
     */

    public function show(string $id)
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
    public function update(Request $request, string $id)
    {
        $control = ServiceControl::find($id);
        $validated = $request->validate([
        'isActive' => 'required|boolean',
    ]);

    $control->update($validated);

    return $this->success([]);
    }
 /**
     * Remove the specified resource from storage.
     *
     * @hideFromAPIDocumentation
     */
    public function destroy(string $id)
    {
        //
    }
}
