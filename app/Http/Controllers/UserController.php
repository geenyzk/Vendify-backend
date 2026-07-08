<?php

namespace App\Http\Controllers;

use App\Class\Payment\Payment;
use App\HttpResponse;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules;
use Illuminate\Validation\ValidationException;

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
        try {
            $validated = $request->validate([
                'fullname' => ['required', 'string', 'max:255'],
                'username' => ['required', 'string', 'max:255', 'unique:' . User::class],
                'email' => ['required', 'string', 'lowercase', 'email', 'max:255', 'unique:' . User::class],
                'phone' => ['required', 'string', 'max:255', 'unique:' . User::class],
                'password' => ['required', Rules\Password::defaults()],
                'user_type' => ['nullable', Rule::in(['user', 'agent', 'api', 'admin', 'bonanza'])],
            ]);
        } catch (ValidationException $e) {
            return $this->fail($e->errors(), "Validation Error", 422);
        }

        $user = User::create([
            'fullname' => $validated['fullname'],
            'username' => $validated['username'],
            'email' => $validated['email'],
            'phone' => $validated['phone'],
            'password' => Hash::make($validated['password']),
            'user_type' => $validated['user_type'] ?? 'user',
            'status' => 'active',
            'role_id' => Role::where('name', 'basic')->value('id'),
        ]);

        // Same as self-registration: provision a virtual funding account, but
        // an unreachable gateway shouldn't abort an admin-created account.
        try {
            Payment::generateAccount($user);
        } catch (\Throwable $e) {
            Log::warning("Could not generate funding account for user {$user->id}: {$e->getMessage()}");
        }

        return $this->success(["user" => $user->fresh()], "User created successfully.", 201);
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
     *
     * wallet_balance is deliberately not accepted here — balance changes must
     * go through POST /admin/users/{id}/fund so a transaction record exists.
     */
    public function update(Request $request, string $id)
    {
        $user = User::findOrFail($id);

        try {
            $validated = $request->validate([
                'fullname' => ['sometimes', 'required', 'string', 'max:255'],
                'username' => ['sometimes', 'required', 'string', 'max:255', Rule::unique('users')->ignore($user->id)],
                'email' => ['sometimes', 'required', 'string', 'lowercase', 'email', 'max:255', Rule::unique('users')->ignore($user->id)],
                'phone' => ['sometimes', 'required', 'string', 'max:255', Rule::unique('users')->ignore($user->id)],
                'user_type' => ['sometimes', Rule::in(['user', 'agent', 'api', 'admin', 'bonanza'])],
                'status' => ['sometimes', Rule::in(['active', 'suspended', 'inactive'])],
                'password' => ['sometimes', 'nullable', Rules\Password::defaults()],
            ]);
        } catch (ValidationException $e) {
            return $this->fail($e->errors(), "Validation Error", 422);
        }

        if (array_key_exists('password', $validated)) {
            if ($validated['password']) {
                $validated['password'] = Hash::make($validated['password']);
            } else {
                unset($validated['password']);
            }
        }

        $user->update($validated);

        return $this->success(["user" => $user->fresh()], "User updated successfully.");
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Request $request, string $id)
    {
        $user = User::findOrFail($id);

        if ((string) $request->user()?->id === (string) $user->id) {
            return $this->fail(null, "You cannot delete your own account.", 422);
        }

        $user->delete();

        return $this->success(null, "User deleted successfully.");
    }

}
