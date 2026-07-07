<?php

namespace App\Http\Controllers;

use App\HttpResponse;
use App\Models\Permission;

class PermissionController extends Controller
{
    use HttpResponse;

    public function index()
    {
        return $this->success(['permissions' => Permission::all()]);
    }
}
