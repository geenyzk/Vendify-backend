<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AdminCreateUserRequest extends FormRequest
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
            'username' => 'required|string|max:255|unique:users,username',
            'fullname' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'phone' => 'required|string|unique:users,phone',
            'password' => 'required|string|min:6|confirmed',
            'user_type' => 'required|string',

            // This below validations can be uncommented if required for admin
            // 'wallet_balance' => 'required|decimal:12,2',
            // 'referral_balance' => 'required|decimal:12,2',
            // 'is_active' => 'required|boolean',
            // 'is_verified' => 'required|boolean',
            // 'pin' => 'required|string',
            // 'status' => 'required|string',
            // 'referral_code' => 'required|string',
            // 'referred_by' => 'required|integer',
            // 'last_login_at' => 'required'
        ];
    }
}
