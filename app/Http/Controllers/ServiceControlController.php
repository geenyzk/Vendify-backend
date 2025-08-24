<?php

namespace App\Http\Controllers;

use App\HttpResponse;
use App\Models\ServiceControl;
use Illuminate\Http\Request;

class ServiceControlController extends Controller
{
    use HttpResponse;

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
        //
    }

    /**
     * Update the specified resource in storage.
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
     */
    public function destroy(string $id)
    {
        //
    }
}
