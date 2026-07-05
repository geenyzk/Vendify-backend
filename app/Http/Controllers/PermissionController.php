<?php

namespace App\Http\Controllers;

use App\Models\Permission;
use Illuminate\Http\JsonResponse;

class PermissionController extends Controller
{
    /**
     * Get all available permissions.
     */
    public function index(): JsonResponse
    {
        $permissions = Permission::orderBy('name')->get();

        return response()->json([
            'success' => true,
            'data' => $permissions->toResourceCollection(),
        ]);
    }
}
