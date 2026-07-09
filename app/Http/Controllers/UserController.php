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
        return $this->success(["users" => User::with('role')->get()->toArray()]);

    }

    /**
     * Mirror of User::pricingTier() plus the staff case — user_type is not
     * read for authorization or pricing anywhere anymore, but it's kept in
     * sync for reporting (AdminController::stats, broadcast targeting,
     * AdminNotifier) whenever the role changes.
     */
    private function userTypeForRole(?Role $role): string
    {
        if (!$role) {
            return 'user';
        }
        if ($role->is_staff) {
            return 'admin';
        }

        return in_array($role->name, ['agent', 'api', 'bonanza'], true) ? $role->name : 'user';
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
                'role_id' => ['nullable', 'exists:roles,id'],
            ]);
        } catch (ValidationException $e) {
            return $this->fail($e->errors(), "Validation Error", 422);
        }

        $role = isset($validated['role_id'])
            ? Role::find($validated['role_id'])
            : Role::where('is_default', true)->first() ?? Role::where('slug', 'basic')->orWhere('name', 'basic')->first();

        $user = User::create([
            'fullname' => $validated['fullname'],
            'username' => $validated['username'],
            'email' => $validated['email'],
            'phone' => $validated['phone'],
            'password' => Hash::make($validated['password']),
            'user_type' => $this->userTypeForRole($role),
            'status' => 'active',
            'role_id' => $role?->id,
        ]);

        // Same as self-registration: provision a virtual funding account, but
        // an unreachable gateway shouldn't abort an admin-created account.
        try {
            Payment::generateAccount($user);
        } catch (\Throwable $e) {
            Log::warning("Could not generate funding account for user {$user->id}: {$e->getMessage()}");
        }

        return $this->success(["user" => $user->fresh(['role'])], "User created successfully.", 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        return $this->success(["user" => User::with('role')->find($id)->toArray()]);
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
                'role_id' => ['sometimes', 'exists:roles,id'],
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

        if (array_key_exists('role_id', $validated)) {
            $validated['user_type'] = $this->userTypeForRole(Role::find($validated['role_id']));
        }

        $user->update($validated);

        return $this->success(["user" => $user->fresh(['role'])], "User updated successfully.");
    }

    /**
     * Issue a login token for a customer so an admin can see the app exactly
     * as that customer does. The token name records who initiated it, so
     * impersonation sessions stay distinguishable from real logins in
     * personal_access_tokens.
     */
    public function impersonate(Request $request, string $id)
    {
        $admin = $request->user();
        $target = User::with('role')->findOrFail($id);

        if ((string) $admin->id === (string) $target->id) {
            return $this->fail(null, "You are already signed in as this user.", 422);
        }

        // Support tooling is for customer accounts only — staff accounts
        // would hand over their admin permissions along with the session.
        if ($target->role && $target->role->is_staff) {
            return $this->fail(null, "Staff accounts cannot be impersonated.", 403);
        }

        $token = $target->createToken("impersonated-by:{$admin->id}");
        Log::info("Admin {$admin->id} ({$admin->username}) started impersonating user {$target->id} ({$target->username}).");

        return $this->success([
            "user" => $target,
            "token" => $token->plainTextToken,
        ], "Impersonation session started.");
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
