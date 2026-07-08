<?php

namespace App\Classes\Payment\Interface;

use App\Models\User;
use Illuminate\Http\Request;

interface PaymentInterface
{
    public function generate(User $user): array|null;
    public function connect(): mixed;
    public function checkBalance(): string;

    /**
     * @return bool false specifically means "signature verification
     * failed" — the caller (Payment::webhook) uses that to return 401
     * instead of the usual 204. Any other outcome (including a caught
     * processing error) returns true, matching the existing "always ack"
     * webhook convention used elsewhere in this app.
     */
    public function webhook(Request $request): bool;
}
