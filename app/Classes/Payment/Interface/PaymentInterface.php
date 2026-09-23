<?php

namespace App\Classes\Payment\Interface;

use App\Classes\Payment\WebhookOutcome;
use App\Models\User;
use Illuminate\Http\Request;

interface PaymentInterface
{
    public function generate(User $user): array|null;
    public function connect(): mixed;
    public function checkBalance(): string;

    /** Distinguishes accepted, permanently rejected, and retryable outcomes. */
    public function webhook(Request $request): WebhookOutcome;
    public function getBanks(): array;
}
