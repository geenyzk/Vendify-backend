<?php

namespace App\Http\Controllers;

use App\HttpResponse;
use App\Models\User;
use Illuminate\Http\Request;

class UserController extends Controller
{

    use HttpResponse;

    public function index()
    {
        //
        return $this->success(["users" => User::all()->toArray()]);

    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        //
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        return $this->success(["user" => User::find($id)->toArray()]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        //
    }

}
