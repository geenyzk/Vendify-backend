<?php

namespace App\Http\Controllers;

use App\Classes\SerivceControl\ServiceControlService;
use App\HttpResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

abstract class Controller
{
    //
    use HttpResponse;

    protected function transactionPinFailure(Request $request): ?JsonResponse
    {
        $userId = $request->user()?->id ?? Auth::id();

        if (ServiceControlService::verifyTransactionPin($userId, $request->input('pin'))) {
            return null;
        }

        return $this->fail([
            'pin' => ['Invalid transaction pin.'],
        ], 'Invalid transaction pin.', 422);
    }
}
