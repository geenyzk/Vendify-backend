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

class AdminController extends Controller
{

    function stats(){
        // USER DONUT CHART DATA
        $raw = User::select('user_type', DB::raw('count(*) as total'))
        ->groupBy('user_type')
        ->get();

        // $tx_raw = Transaction::select('user_type', DB::raw('count(*) as total'))
        // ->groupBy('user_type')
        // ->get();

        // $tx_labels = $tx_raw->pluck("");
        $labels = $raw->pluck('user_type');

        $transactionCount = Transaction::whereYear('created_at', Carbon::now()->year)
        ->whereMonth('created_at', Carbon::now()->month)
        ->count();

        // Consumed by the dashboard's stat cards (vtu_2 dashboardService.ts's
        // Stats type) alongside the fields above — kept as plain today-only
        // counts here since the richer date-range breakdown lives in
        // AnalyticsController::index() instead.
        $totalFundingToday = Transaction::whereDate('created_at', Carbon::today())
            ->where('status', 'success')
            ->whereIn('transaction_type', ['wallet_funding', 'manual_funding'])
            ->sum('amount');
        $totalSignupsToday = User::whereDate('created_at', Carbon::today())->count();

        return $this->success([
            "users_graph" => [
                'labels' => $labels,
                'data' => $raw,
            ],
            "transaction_count" => $transactionCount,
            "total_user" => User::count(),
            "total_user_balance" => User::sum("wallet_balance"),
            "api_balances" => VendorFactory::sumAllBalances(),
            "total_funding_today" => (float) $totalFundingToday,
            "total_signups_today" => $totalSignupsToday,
            "affiliates" => $this->affiliateSummary(),
            "tx_chart" => [
                "labels" => [], // $tx_labels
                "data" => [], // $tx_labels
            ]
        ]);
    }

    // Phase 1 of the parent/child affiliate system has no dashboard
    // presence at all yet — this is the aggregate view; per-instance
    // detail already lives on the "Affiliates" admin page.
    private function affiliateSummary(): array
    {
        $instances = \App\Models\ChildInstance::all(['id', 'status', 'last_seen_at']);
        $staleCutoff = now()->subMinutes(15);

        return [
            'total' => $instances->count(),
            'active' => $instances->where('status', 'active')->count(),
            'pending' => $instances->where('status', 'pending')->count(),
            'stale' => $instances->where('status', 'active')
                ->filter(fn ($i) => !$i->last_seen_at || $i->last_seen_at->lt($staleCutoff))
                ->count(),
            'total_synced_customers' => \App\Models\ChildCustomer::count(),
            'total_synced_transactions' => \App\Models\ChildTransaction::count(),
            'total_synced_transaction_volume' => (float) \App\Models\ChildTransaction::sum('amount'),
        ];
    }

    function systemInformation() {
        return $this->success(["general" => General::first() ]);
    }

    function airtimeDiscount () {
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

    // Public catalog tables — the storefront reads these before login (see
    // vtu_2's customerService.ts). Every other table requires an
    // authenticated admin. Can't express this split as two GET routes with
    // the same URI (Laravel's route collection is keyed by method+URI, so a
    // second identical registration replaces the first rather than adding a
    // constrained alternative) — enforced here instead.
    // data_plans/cable_plans deliberately excluded even though
    // customerService.ts's comments call them "public" — their price
    // accessor (DataPlan::getPriceAttribute(), CablePlan::getPriceAttribute())
    // unconditionally calls Auth::user()->user_type, which fatals on a
    // guest request regardless of route auth. They only ever work
    // authenticated in practice (mid-purchase-flow), so gating them behind
    // auth below changes nothing for real usage and turns their previous
    // 500 into a clean 401 for an actual guest request.
    private const PUBLIC_TABLES = [
        'networks', 'airtime_plans', 'network_types', 'bill_plans',
    ];

    static function universalGet(Request $request, $modelSlug)
    {
        $self = new Self();

        if (!in_array($modelSlug, self::PUBLIC_TABLES, true)) {
            $user = $request->user('sanctum');
            if (!$user) {
                return $self->fail([], 'Unauthenticated.', 401);
            }
            if (!$user->role || !$user->role->is_staff) {
                return $self->fail([], 'Unauthorized.', 403);
            }
        }

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


    static function universalCreateOrUpdate(Request $request, $table, $id)
    {
        // Check table exists
        $self = new Self();
        if (!Schema::hasTable($table)) {
            return response()->json(['error' => 'Table not found'], 404);
        }

        $data = $request->all();
        if (empty($data)) {
            return response()->json(['error' => 'No data provided'], 400);
        }

        // Get valid columns
        $tableColumns = Schema::getColumnListing($table);
        $filteredData = array_filter($data, fn($key) => in_array($key, $tableColumns), ARRAY_FILTER_USE_KEY);

        if (empty($filteredData)) {
            return response()->json(['error' => 'No valid columns provided'], 400);
        }

        try {
            // Ensure 'id' is also in the table columns
            if (!in_array('id', $tableColumns)) {
                return response()->json(['error' => 'Table does not have an id column'], 400);
            }

            // Set the match condition for updateOrInsert
            $match = ['id' => $id];

            // Perform create or update
            $updatedOrInserted = DB::table($table)->updateOrInsert($match, $filteredData);

            if ($updatedOrInserted) {
                return $self->success([], 'Create or update successful');
            } else {
                return $self->success([], 'No changes were made', "info");
            }
        } catch (\Exception $e) {
            return $self->fail([], $e->getMessage(), 500);
        }
    }

    static function universalBulkCreateOrUpdate(Request $request, $table)
    {
        $self = new Self();

        // Check table exists
        if (!Schema::hasTable($table)) {
            return response()->json(['error' => 'Table not found'], 404);
        }

        $items = $request->input('items'); // Expecting: [ { id: ..., field1: ..., field2: ... }, ... ]

        if (!is_array($items) || empty($items)) {
            return response()->json(['error' => 'No valid data array provided'], 400);
        }

        $tableColumns = Schema::getColumnListing($table);

        // Make sure the table has an ID column
        if (!in_array('id', $tableColumns)) {
            return response()->json(['error' => 'Table does not have an id column'], 400);
        }

        $results = [];
        try {
            foreach ($items as $item) {
                if (!isset($item['id'])) {
                    continue; // Skip items without an ID
                }

                // Filter valid columns
                $filteredData = array_filter(
                    $item,
                    fn($key) => in_array($key, $tableColumns),
                    ARRAY_FILTER_USE_KEY
                );

                if (!empty($filteredData)) {
                    $match = ['id' => $item['id']];
                    $status = DB::table($table)->updateOrInsert($match, $filteredData);
                    $results[] = [
                        'id' => $item['id'],
                        'status' => $status ? 'updated/inserted' : 'unchanged'
                    ];
                }
            }

            return $self->success($results, 'Bulk create or update completed');
        } catch (\Exception $e) {
            return $self->fail([], $e->getMessage(), 500);
        }
    }

    public function universalDelete(Request $request, $table, $id)
    {
        // Ensure the table exists
        if (!Schema::hasTable($table)) {
            return $this->success([], 'Table not found', 404, 'info');
        }

        try {
            // Attempt to delete the record
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
            'child_instances' => \App\Models\ChildInstance::class,
            'child_customers' => \App\Models\ChildCustomer::class,
            'child_transactions' => \App\Models\ChildTransaction::class,
            'child_directives' => \App\Models\ChildDirective::class,
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

    // ChildInstance::shared_secret is $hidden on the model (it's a real
    // credential, not something that should leak into every generic
    // /table/child_instances list/show response) — these two admin-only
    // actions are the only way to actually see it, mirroring refreshToken()
    // above for vendor identifiers.
    function childInstanceSecret(string $id)
    {
        $instance = \App\Models\ChildInstance::find($id);
        if (!$instance) {
            return $this->fail([], 'Affiliate not found', 404);
        }
        return $this->success(['secret' => $instance->shared_secret]);
    }

    function regenerateChildInstanceSecret(string $id)
    {
        $instance = \App\Models\ChildInstance::find($id);
        if (!$instance) {
            return $this->fail([], 'Affiliate not found', 404);
        }
        $instance->shared_secret = Str::random(64);
        $instance->save();
        return $this->success(['secret' => $instance->shared_secret]);
    }

    // No manual "create affiliate" form — an admin only ever needs to give
    // a new child a name and a one-time code. The child turns that code
    // into its own real slug/secret via ChildRegistrationController::register()
    // the first time it connects; nothing else about the connection (base_url,
    // status) is admin-configured up front.
    function generateChildRegistrationCode(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
        ]);

        $instance = \App\Models\ChildInstance::create([
            'name' => $validated['name'],
            'registration_code' => Str::upper(Str::random(10)),
            'registration_code_expires_at' => now()->addDay(),
        ]);

        return $this->success([
            'id' => $instance->id,
            'name' => $instance->name,
            'registration_code' => $instance->registration_code,
            'expires_at' => $instance->registration_code_expires_at,
        ], 'Registration code generated');
    }

    // Admin-initiated half of the directive outbox. The other half —
    // ChildDirectiveController::index()/ack() — is the child polling this
    // same table for pending rows, gated by verify.child.hmac. Kept as its
    // own dedicated endpoint rather than the generic /table/child_directives
    // write path: the Universal Table CRUD's create routes all require an
    // id up front (updateOrInsert keyed on it), so they only ever support
    // editing an existing row, not creating a brand new one.
    function createChildDirective(Request $request, string $id)
    {
        $instance = \App\Models\ChildInstance::find($id);
        if (!$instance) {
            return $this->fail([], 'Affiliate not found', 404);
        }

        $validated = $request->validate([
            'type' => 'required|string|max:100',
            'payload' => 'nullable|array',
        ]);

        $directive = \App\Models\ChildDirective::create([
            'child_instance_id' => $instance->id,
            'type' => $validated['type'],
            'payload' => $validated['payload'] ?? [],
            'status' => 'pending',
        ]);

        return $this->success($directive, 'Directive queued');
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
