<?php

namespace App\Http\Resources;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Auth;

class UserResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'fullname' => $this->fullname,
            'username' => $this->username,
            'email' => $this->email,
            'phone' => $this->phone ?? null,
            'wallet_balance' => $this->wallet_balance,
            'role' => $this->role,
            'role_id' => $this->role_id,
            'user_type' => $this->user_type,
            'status' => $this->status,
            'created_at' => $this->created_at,
            'joined_at' => Carbon::parse($this->created_at)->diffForHumans(),
            'updated_at' => $this->updated_at,
            'deleted_at' => $this->deleted_at,
            'email_verified_at' => $this->email_verified_at,
            'is_verified' => $this->email_verified_at !== null,
            'transactions' => $this->transactions,
            // 'stats' => $this->when(Auth::user()->hasRole("admin"), $this->stat)
        ];
    }
}
