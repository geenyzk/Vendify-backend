<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class AccountController extends Controller
{
    public function updatePin(Request $request)
    {
        try {
            $validated = $request->validate([
                'pin' => ['required', 'digits:4', 'confirmed'],
                'current_pin' => ['nullable', 'digits:4'],
            ]);

            $user = $request->user();

            if ($user->pin && ($validated['current_pin'] ?? null) !== $user->pin) {
                return $this->fail(
                    ['current_pin' => ['Current PIN is incorrect.']],
                    'Validation Error',
                    422
                );
            }

            $user->forceFill([
                'pin' => $validated['pin'],
            ])->save();

            return $this->success(null, 'Transaction PIN saved.');
        } catch (ValidationException $e) {
            return $this->fail($e->errors(), 'Validation Error', 422);
        }
    }
}
