<?php

namespace App\Http\Controllers;

use App\Classes\TemplateParser;
use App\Classes\TransactionService;
use App\Classes\Vendor\VendorFactory;
use App\Models\Discount;
use App\Models\General;
use App\Models\Provider;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Vendor;
use App\Notifications\BroadcastNotification;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Schema;

/**
 * @group Admin Management
 *
 * APIs for dashboard statistics, universal table access, broadcasting notifications, and wallet funding.
 */

class AdminController extends Controller
{

        /**
     * Dashboard statistics overview.
     *
     * Returns user chart data, transaction counts, total user balance, and more.
     *
     * @response 200 {
     *    "users_graph": {
     *        "labels": ["user", "admin"],
     *        "data": [{"user_type": "user", "total": 23}]
     *    },
     *    "transaction_count": 10,
     *    "total_user": 50,
     *    "total_user_balance": 12000,
     *    "api_balances": {...},
     *    "total_funding_today": 5000,
     *    "total_signups_today": 3,
     *    "transaction_status": {
     *        "successful": 8,
     *        "failed": 1,
     *        "pending": 1
     *    },
     *    "tx_chart": {
     *        "labels": [],
     *        "data": []
     *    }
     * }
     */
    function stats()
    {
        $days = collect();
        for ($i = 6; $i >= 0; $i--) {
            $days->push(Carbon::today()->subDays($i)->format('Y-m-d'));
        }


        // Current year and month
        $year = Carbon::now()->year;
        $month = Carbon::now()->month;

        $transactionCount = Transaction::whereYear('created_at', $year)
            ->whereMonth('created_at', $month)
            ->count();

        // Current month transaction status counts
        $successfulTx = Transaction::where('status', 'success')
            ->whereYear('created_at', $year)
            ->whereMonth('created_at', $month)
            ->count();

        $failedTx = Transaction::where('status', 'fail')
            ->whereYear('created_at', $year)
            ->whereMonth('created_at', $month)
            ->count();

        $pendingTx = Transaction::where('status', 'pending')
            ->whereYear('created_at', $year)
            ->whereMonth('created_at', $month)
            ->count();

        $totalFundingToday = Transaction::where('transaction_type', 'funding')
        ->whereDate('created_at', Carbon::today())
        ->sum('amount');

        $totalSignupsToday = User::whereCreatedAt(Carbon::today())->count();

        $usersRaw = User::select('user_type', DB::raw('count(*) as total'))
    ->groupBy('user_type')
    ->get();

    // Extract labels and values
    $user_labels = $usersRaw->pluck('user_type')->toArray();
    $user_values = $usersRaw->pluck('total')->toArray();

    // Donut chart structure for Chart.js
    $user_chart = [
        'labels' => $user_labels,
        'datasets' => [
            [
                'label' => 'Users by Type',
                'data' => $user_values,
                'backgroundColor' => [
                    '#36A2EB',
                    '#FF6384',
                    '#FFCE56',
                    '#4BC0C0',
                    '#9966FF',
                ], // Add more colors if needed
                'borderColor' => [
                    '#36A2EB',
                    '#FF6384',
                    '#FFCE56',
                    '#4BC0C0',
                    '#9966FF',
                ]
            ]
        ]
    ];


        $txData = Transaction::select(
            DB::raw('DATE(created_at) as date'),
            DB::raw('count(*) as total')
        )
        ->whereDate('created_at', '>=', Carbon::today()->subDays(6))
        ->groupBy(DB::raw('DATE(created_at)'))
        ->orderBy('date')
        ->get()
        ->keyBy('date');


        foreach ($days as $day) {
            $tx_labels[] = Carbon::parse($day)->format('D'); // e.g., Mon, Tue
            $tx_values[] = $txData[$day]->total ?? 0; // 0 if no transaction
        }

        $tx_chart = [
            'labels' => $tx_labels,
            'datasets' => [
                [
                    'label' => 'Transactions',
                    'data' => $tx_values,
                    'backgroundColor' => '#36A2EB',
                    "borderRadius" => 8
                ]
            ]
        ];

        return $this->success([
            "users_graph" =>$user_chart,
            "transaction_count" => $transactionCount,
            "total_user" => User::count(),
            "total_user_balance" => User::sum("wallet_balance"),
            "api_balances" => VendorFactory::sumAllBalances(),
            "total_funding_today" => $totalFundingToday,
            "total_signups_today" => $totalSignupsToday,


            "transaction_status" => [
                "successful" => $successfulTx,
                "failed" => $failedTx,
                "pending" => $pendingTx,
            ],

            "tx_chart" => $tx_chart
        ]);
    }


        /**
     * Get general system information.
     *
     * @response 200 {
     *    "general": {
     *        "site_name": "MyApp",
     *        "support_email": "support@example.com"
     *    }
     * }
     * @authenticated
     */
    function systemInformation()
     {
        return $this->success(["general" => General::first() ]);
    }

        /**
     * Get airtime discount configuration.
     *
     * @response 200 {
     *    "discount": {
     *        "network": "mtn",
     *        "discount_rate": 3.5
     *    }
     * }
     */
    function airtimeDiscount()
 {
        $discount = Discount::airtime();
        return $this->success(["discount" => $discount]);
    }


    public function universalShow($table, $id)
    {
        if (!Schema::hasTable($table)) {
            return $this->fail([], 'Table not found', 404);
        }

        $modelClass = $this->getModelClassFromTable($table);
        if (!class_exists($modelClass)) {
            return $this->fail([], 'Model not found for table', 404);
        }

        try {
            $record = $modelClass::find($id);
            if (!$record) {
                return $this->fail([], 'Record not found', 404);
            }
            $data = $record->toArray();

            $serverGroups = [];

            // Loop through keys and group server values by prefix (e.g., adex, spurs)
            foreach ($data as $key => $value) {
                $matches = [];
                if (preg_match('/^(adex|spurs|simhost|msorg|vtpass|payscribe)(_server_\d+)?$/', $key, $matches)) {
                    $prefix = $matches[1];

                    $serverGroups[$prefix][] = [
                            'key' => $key,
                            'value' => $value ?? "0"
                        ];
                }
            }

            return $this->success([...$data, 'servers' => $serverGroups ]);

        } catch (\Exception $e) {
            return $this->fail([],$e->getMessage(), 500);
        }
    }

    static function universalGet(Request $request, $modelSlug)
    {
        $self = new Self();

        $modelClass = $self->getModelClassFromTable($modelSlug);
        if (!$modelClass || !class_exists($modelClass)) {
            return $self->fail([], "Model not found for slug: $modelSlug", 404);
        }

        $modelInstance = new $modelClass();
        $table = $modelInstance->getTable();


        if (!Schema::hasTable($table)) {
            return $self->fail([], "Table not found: $table", 404);
        }

        try {
            $query = $modelClass::query();

            foreach ($request->query() as $column => $value) {
                if (Schema::hasColumn($table, $column)) {
                    $query->where($column, $value);
                }
            }

            $records = $query->get();

            return $self->success($records);

        } catch (\Exception $e) {
            return $self->fail([], $e->getMessage(), 500);
        }
    }


    public static function universalBulkCreateOrUpdate(Request $request, $table)
    {
        $self = new self();

        // ✅ Check if table exists
        if (!Schema::hasTable($table)) {
            return response()->json(['error' => 'Table not found'], 404);
        }

        $items = $request->input('items');

        // ✅ Validate input
        if (!is_array($items) || empty($items)) {
            return response()->json(['error' => 'No valid data array provided'], 400);
        }

        // ✅ Get column list
        $tableColumns = Schema::getColumnListing($table);

        if (!in_array('id', $tableColumns)) {
            return response()->json(['error' => 'Table does not have an "id" column'], 400);
        }

        $results = [];

        try {
            DB::beginTransaction();

            foreach ($items as $item) {

                Log::info('Received item:', $item);

                if (!is_array($item) || !isset($item['id'])) {
                    continue;
                }
                Log::info('Received item:', $item);

                // Filter only valid columns
                $filteredData = array_filter(
                    $item,
                    fn($key) => in_array($key, $tableColumns),
                    ARRAY_FILTER_USE_KEY
                );

                foreach (['created_at', 'updated_at'] as $dateField) {
                    if (isset($filteredData[$dateField]) && !empty($filteredData[$dateField])) {
                        try {
                            $filteredData[$dateField] = \Carbon\Carbon::parse($filteredData[$dateField])
                                ->format('Y-m-d H:i:s');
                        } catch (\Exception $e) {
                            // If parsing fails, just unset the field to avoid breaking
                            unset($filteredData[$dateField]);
                        }
                    }
                }

                if (!empty($filteredData)) {
                    $match = ['id' => $item['id']];
                    $status = DB::table($table)->updateOrInsert($match, $filteredData);

                    $results[] = [
                        'id' => $item['id'],
                        'status' => $status ? 'updated/inserted' : 'unchanged',
                    ];
                }
            }

            DB::commit();

            return $self->success($results, 'Bulk create or update completed');
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('Bulk operation failed', ['error' => $e->getMessage()]);
            return $self->fail([], 'Server error: ' . $e->getMessage(), 500);
        }
    }

    public static function universalCreateOrUpdate(Request $request, $table, $id)
    {
        $items = [$request->all()]; // wrap single item in array
        $items[0]['id'] = $id;      // ensure 'id' is set

        return self::universalBulkCreateOrUpdate(new Request(['items' => $items]), $table);
    }


    public function universalDelete(Request $request, $table, $id)
    {
        // Ensure the table exists
        if (!Schema::hasTable($table)) {
            return $this->success([], 'Table not found', 404, 'info');
        }

        try {
            // Attempt to delete the recorduu
            $deleted = DB::table($table)->where('id', $id)->delete();

            if ($deleted) {
                return $this->success([],'Record deleted successfully');
            } else {
                return $this->fail([], 'No matching record found or already deleted', 404);
            }

        } catch (\Exception $e) {
                return $this->fail([], $e->getMessage(), 500);
        }
    }
    private function getModelClassFromTable(string $table): string
    {
        $manualMap = [
            'vendors'   => \App\Models\Vendor::class,
            'providers' => Provider::class,
            // Add more custom mappings if needed
        ];
        if (isset($manualMap[$table])) {
            return $manualMap[$table];
        }
        $modelName = Str::studly(Str::singular($table));
        return "App\\Models\\{$modelName}";
    }


    public function fundUser(Request $request, $id)
    {
        $validated = $request->validate([
            'amount' => 'required|numeric|min:0.01',
            'type' => 'required|in:credit,debit',
        ]);

        $user = User::findOrFail($id);

        try {
            $transaction = TransactionService::fundUser($user, $validated['amount'], $validated['type']);
                // 'message' => ,

            return  $this->success([ 'user' => User::find($id)], 'User wallet ' . ($validated['type'] === 'credit' ? 'funded' : 'debited') . ' successfully.');
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Transaction failed: ' . $e->getMessage(),
            ], 400);
        }
    }


    function refreshToken(Request $re, string $id){

        $vendor = Provider::find($id);
        $vendor->identifier = Str::uuid();
        $vendor->save();
        return $this->success(["identifier" => $vendor->identifier]);
    }

    function broadcast(Request $request){
        $validated = $request->validate([
            'channels' => 'required|array',
            'recipients' => 'required|array',
            'smsMessage' => 'nullable|string|max:160',
            'emailSubject' => 'nullable|string',
            'emailBody' => 'nullable|string',
            'notifTitle' => 'nullable|string',
            'notifMessage' => 'nullable|string',
            'sendNow' => 'required|boolean',
            'scheduleDate' => 'nullable|date',
            'priorityHigh' => 'required|boolean',
        ]);

       $recipients = User::whereIn('user_type', $validated['recipients'])->get();
       foreach ($recipients as $user) {
        $transaction = Transaction::whereUserId($user->id)->latest()->first(); // or get relevant transaction
        $general = General::first(); // or use a config or cached settings

        $context = [
            'user' => $user,
            'transaction' => $transaction,
            'general' => $general,
        ];

        $parser = TemplateParser::make()->with($context);

        $data = [
            'channels' => $validated['channels'],
            'smsMessage' => $parser->parse($validated['smsMessage'] ?? ""),
            'emailSubject' => $parser->parse($validated['emailSubject'] ?? ""),
            'emailBody' => $parser->parse($validated['emailBody'] ?? ""),
            'notifTitle' => $parser->parse($validated['notifTitle'] ?? ""),
            'notifMessage' => $parser->parse($validated['notifMessage'] ?? ""),
            'priorityHigh' => $validated['priorityHigh'],
        ];

        $notification = new BroadcastNotification($data);
        if (!$validated['sendNow']) {
            Log::info("delayed...");
            $notification->delay(Carbon::parse($validated['scheduleDate']));
        }

        // Log::warning($notification);

        Notification::route('mail', $user->email)
            ->route('sms', $user->phone)
            ->notify($notification);
            Log::info('Broadcast sent to users: ' . $user->username);
    }

    }
}
