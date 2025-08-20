<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

use App\Models\Transaction;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class TransactionController extends Controller
{
    //php

    public function report(Request $request)
    {
        $startDate = Carbon::parse($request->input('start_date', now()->startOfMonth()))->startOfDay();
        $endDate = Carbon::parse($request->input('end_date', now()))->endOfDay();
        $transactions = Transaction::calculateSummary($startDate, $endDate, $request->input("user_id"));
        return response()->json([
            'start_date' => $startDate->toDateString(),
            'end_date' => $endDate->toDateString(),
            'transactions' => $transactions,
        ]);
}


}
