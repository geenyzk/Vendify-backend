<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AdminUpdateUserRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'username' => 'sometimes|string|max:255',
            'fullname' => 'sometimes|string|255',
            'email' => 'sometimes|email|unique:users,email',
            'phone' => 'sometimes|string|unique:users,phone',
            'password' => 'sometimes|string|min:6',
            'user_type' => 'sometimes|string',
            'wallet_balance' => 'sometimes|decimal:12,2',
            'referral_balance' => 'sometimes|decimal:12,2',
            'is_active' => 'sometimes|boolean',
            'is_verified' => 'sometimes|boolean',
            'pin' => 'sometimes|string',
            'status' => 'sometimes|string',
            'referral_code' => 'sometimes|string',
            'referred_by' => 'sometimes|integer',
            'last_login_at' => 'sometimes'
        ];
    }
}
