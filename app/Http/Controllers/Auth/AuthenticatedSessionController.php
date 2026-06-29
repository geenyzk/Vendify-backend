<?php

namespace App\Http\Controllers\Auth;

use App\Class\Payment\Payment;
use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use App\HttpResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use Illuminate\Validation\ValidationException;

class AuthenticatedSessionController extends Controller
{

    use HttpResponse;

    public function store(LoginRequest $request)
    {

        try{
            $request->authenticate();
            $user = Auth::user();
            $token = $user->createToken($user->username);
            Payment::generateAccount($user);
            return $this->success(["user" =>$user, 'token' => $token->plainTextToken]);
        } catch (ValidationException $e) {
            return $this->fail( $e->errors(), "Validation Error", 422);
        }
    }


    public function index(Request $request){
        return $this->success(["user" => $request->user()]);
    }
    /**
     * Destroy an authenticated session.
     */
    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();

        $request->session()->regenerateToken();

        return redirect('/');
    }
}
