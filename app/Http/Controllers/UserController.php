<?php

namespace App\Http\Controllers;

use App\Http\Requests\AdminCreateUserRequest;
use App\Http\Requests\AdminUpdateUserRequest;
use App\HttpResponse;
use App\Services\Admin\UserService;

class UserController extends Controller
{
    use HttpResponse;

    public function __construct(
        protected UserService $userService
    ){}

    public function index()
    {
        return $this->success([
            "users" => $this->userService->getAllUsers()
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(AdminCreateUserRequest $request)
    {
        $validated = $request->validated();
        $user = $this->userService->createUser($validated);
        $token = $user->createToken($user->username)->plainTextToken; //token can be removed if not needed for admin

        return $this->success([
            'user' => $user,
            'user_token' => $token
        ]);
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        return $this->success([
            "user" => $this->userService->getUser($id)
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(AdminUpdateUserRequest $request, string $id)
    {
        $validated = $request->validated();
        $user = $this->userService->updateUser($id, $validated);
        return $this->success(['user' => $user]);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        $this->userService->deleteUser($id);
        return $this->success(['message' => 'User deleted']);
    }
}