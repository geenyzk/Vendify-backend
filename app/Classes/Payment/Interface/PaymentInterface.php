<?php

namespace App\Classes\Payment\Interface;

use App\Models\User;
use Illuminate\Http\Request;

interface PaymentInterface
{
    public function generate(User $user): array|null;
    public function connect(): mixed;
    public function checkBalance(): string;
    public function webhook(Request $request): void;
}
