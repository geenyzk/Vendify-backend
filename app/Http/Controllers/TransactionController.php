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
<<<<<<< HEAD
=======
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

    /**
 * Transaction Report
 *
 * @group Transactions
 *
 * This endpoint returns a summary of transactions between a given date range.
 * It can also filter transactions by a specific user if the `user_id` is provided.
 *
 * @queryParam start_date date Optional. The start date of the report in YYYY-MM-DD format. Defaults to the first day of the current month. Example: 2025-08-01
 * @queryParam end_date date Optional. The end date of the report in YYYY-MM-DD format. Defaults to today. Example: 2025-08-07
 * @queryParam user_id integer Optional. The ID of the user to filter transactions. Example: 12
 *
 * @response 200 {
 *   "start_date": "2025-08-01",
 *   "end_date": "2025-08-07",
 *   "transactions": {
 *     "total": 50000,
 *     "count": 25,
 *     "breakdown": {
 *       "deposit": 30000,
 *       "withdrawal": 20000
 *     }
 *   }
 * }
 */

    public function report(Request $request)
>>>>>>> bbdf8dbc93811b942956ea2015f977bbc20327d4
{
    $startDate = Carbon::parse($request->input('start_date', now()->startOfMonth()))->startOfDay();
    $endDate = Carbon::parse($request->input('end_date', now()))->endOfDay();

    $transactions = Transaction::calculateSummary($startDate, $endDate, $request->input("user_id"));


    return response()->json([
        'start_date' => $startDate->toDateString(),
        'end_date' => $endDate->toDateString(),
        'transactions' => $transactions,
    ]);
<<<<<<< HEAD
=======

>>>>>>> bbdf8dbc93811b942956ea2015f977bbc20327d4
}


}
