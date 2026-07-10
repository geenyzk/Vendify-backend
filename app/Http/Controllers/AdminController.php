<?php

namespace App\Http\Controllers;

use App\Classes\TransactionService;
use App\Classes\Vendor\VendorFactory;
use App\Models\ChildCustomer;
use App\Models\ChildInstance;
use App\Models\ChildTransaction;
use App\Models\Discount;
use App\Models\General;
use App\Models\Provider;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Vendor;
use Carbon\Carbon;
use Exception;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;

/**
 * @group Admin Management
 */
class AdminController extends Controller
{
    // --- Stats & Dashboard Methods (Kept largely the same) ---

    public function stats()
    {
        return $this->success([
            'users_graph' => $this->buildUserChart(),
            'transaction_count' => $this->getMonthlyTransactionCount(),
            'total_user' => User::count(),
            'total_user_balance' => User::sum('wallet_balance'),
            'api_balances' => VendorFactory::sumAllBalances(),
            'total_funding_today' => $this->getTodayFundingTotal(),
            'total_signups_today' => $this->getTodaySignupsCount(),
            'sales_chart' => $this->buildSalesChart(),
            'affiliates' => $this->buildAffiliateSummary(),
            'transaction_status' => $this->getTransactionStatus(),
            'tx_chart' => $this->buildTransactionChart(),
            // 'api_balance' => VendorFactory::sumAllBalances()
        ]);
    }

    // ... [Private chart building methods: getLast7Days, buildUserChart, etc. kept as is] ...
    // (Assuming these private helper methods exist as per your original file.
    // I am omitting them here for brevity, but they should remain in the class.)

    private function getLast7Days()
    {
        $days = collect();
        for ($i = 6; $i >= 0; $i--) {
            $days->push(Carbon::today()->subDays($i)->format('Y-m-d'));
        }
        return $days;
    }

    private function buildUserChart(): array
    {
        $usersRaw = User::select('user_type', DB::raw('count(*) as total'))
            ->groupBy('user_type')
            ->get();
        $colors = ['#36A2EB', '#FF6384', '#FFCE56', '#4BC0C0', '#9966FF'];
        return [
            'labels' => $usersRaw->pluck('user_type')->toArray(),
            'datasets' => [[
                'label' => 'Users by Type',
                'data' => $usersRaw->pluck('total')->toArray(),
                'backgroundColor' => $colors,
                'borderColor' => $colors,
            ]]
        ];
    }

    private function getMonthlyTransactionCount(): int
    {
        $now = Carbon::now();
        return Transaction::whereYear('created_at', $now->year)->whereMonth('created_at', $now->month)->count();
    }

    private function getTransactionStatus(): array
    {
        $now = Carbon::now();
        $baseQuery = Transaction::whereYear('created_at', $now->year)->whereMonth('created_at', $now->month);
        return [
            'successful' => (clone $baseQuery)->where('status', 'success')->count(),
            'failed' => (clone $baseQuery)->where('status', 'fail')->count(),
            'pending' => (clone $baseQuery)->where('status', 'pending')->count(),
        ];
    }

    private function getTodayFundingTotal(): float
    {
        return Transaction::where('transaction_type', 'funding')->whereDate('created_at', Carbon::today())->sum('amount');
    }

    private function getTodaySignupsCount(): int
    {
        return User::whereDate('created_at', Carbon::today())->count();
    }

    private function buildTransactionChart(): array
    {
        $days = $this->getLast7Days();
        $txData = Transaction::select(DB::raw('DATE(created_at) as date'), DB::raw('count(*) as total'))
            ->whereDate('created_at', '>=', $days->first())
            ->groupBy(DB::raw('DATE(created_at)'))->orderBy('date')->get()->keyBy('date');

        $labels = [];
        $values = [];
        foreach ($days as $day) {
            $labels[] = Carbon::parse($day)->format('D');
            $values[] = $txData[$day]->total ?? 0;
        }
        return ['labels' => $labels, 'datasets' => [['label' => 'Transactions', 'data' => $values, 'backgroundColor' => '#36A2EB', 'borderRadius' => 8]]];
    }

    private function buildAffiliateSummary(): array
    {
        $total = ChildInstance::count();
        $active = ChildInstance::where('status', 'active')->count();
        $pending = ChildInstance::where('status', 'pending')->count();
        $stale = ChildInstance::where('status', 'active')
            ->where('last_seen_at', '<', Carbon::now()->subMinutes(15))
            ->count();

        return [
            'total' => $total,
            'active' => $active,
            'pending' => $pending,
            'stale' => $stale,
            'total_synced_customers' => ChildCustomer::count(),
            'total_synced_transactions' => ChildTransaction::count(),
            'total_synced_transaction_volume' => (float) ChildTransaction::sum('amount'),
        ];
    }

    private function buildSalesChart(): array
    {
        $salesData = Transaction::select('transaction_type', DB::raw('SUM(amount) as total'))
            ->whereDate('created_at', Carbon::today())->where('status', 'success')
            ->groupBy('transaction_type')->pluck('total', 'transaction_type')->toArray();

        $categories = ['airtime_recharge' => 'Airtime VTU', 'data_subscription' => 'Data Bundle', 'airtime_pin' => 'Airtime Pin', 'cable_subscription' => 'Cable Sales', 'electric_bill' => 'Bill Sales'];
        $colors = ['#36A2EB', '#FF6384', '#FFCE56', '#9966FF'];
        $chart = [];
        foreach ($categories as $key => $label) {
            $chart[] = ['name' => $label, 'value' => $salesData[$key] ?? 0, 'fill' => $colors[count($chart) % count($colors)]];
        }
        return $chart;
    }

    // --- System Info Methods ---

    public function systemInformation()
    {
        $general = General::first();
        return $general ? $this->success(['general' => $general]) : $this->fail([], 'System information not configured', 404);
    }

    public function airtimeDiscount()
    {
        $discounts = Discount::where('service_type', 'airtime')->get();
        return $this->success(['discount' => $discounts]);
    }

    /**
     * Run pending framework/database migrations (admin-only).
     */
    public function migrateDb(Request $request)
    {
        $admin = $request->user();
        if (!$admin || !$admin->role || !$admin->role->is_staff || !$admin->role->hasPermission('migrations')) {
            return $this->fail([], 'Unauthorized', 403);
        }

        try {
            $exit = Artisan::call('migrate', ['--force' => true]);
            $output = Artisan::output();
            return $this->success(['exit_code' => $exit, 'output' => $output], 'Migrations executed');
        } catch (\Throwable $e) {
            return $this->fail(['error' => $e->getMessage()], 'Migration failed', 500);
        }
    }

    // --- Optimized Universal Methods ---

    /**
     * Get records from a table with filtering and eager loading.
     * Supports ?with=relation1,relation2 and ?sort=column,desc
     */
    // Tables the generic reader must never serve: GET /table/{table} is
    // reachable by any logged-in user (not just admins), so anything holding
    // other users' PII (phone numbers, proof uploads, submitted amounts,
    // bank account details) needs its own permission-gated controller
    // instead — see AirtimeToCashController / WalletWithdrawalController.
    private const RESTRICTED_TABLES = ['airtime_to_cash_requests', 'wallet_withdrawals', 'broadcasts'];

    // The ONLY tables a non-admin (a logged-in customer) may read through the
    // generic table API — the product catalog the storefront needs to render.
    // Everything else (providers with plaintext api_key/secret_key/password,
    // users with wallet balances/PII, transactions, banks, settings, the
    // child-sync tables, …) is admin-only: without this gate any registered
    // customer could GET /table/providers and walk off with every vendor and
    // payment-gateway credential.
    private const PUBLIC_TABLES = [
        'networks', 'network_types', 'data_plans', 'cable_plans',
        'bill_plans', 'airtime_plans', 'exam_plans',
        'airtime_pin_plans', 'data_pin_plans', 'disco_provider_ids',
    ];

    /**
     * 403 unless the caller may read this table: catalog tables are open to
     * any authenticated user; everything else requires a staff role. Returns
     * a JsonResponse to short-circuit with, or null to proceed.
     */
    private function denyIfPrivateRead(Request $request, string $table): ?\Illuminate\Http\JsonResponse
    {
        if (in_array($table, self::PUBLIC_TABLES, true)) {
            return null;
        }

        // Staff-ness is the role, not user_type (see EnsureUserIsAdmin).
        if ($request->user()?->role?->is_staff) {
            return null;
        }

        return $this->fail([], 'You are not allowed to read this resource.', 403);
    }

    public function universalGet(Request $request, $modelSlug)
    {
        if (in_array($modelSlug, self::RESTRICTED_TABLES, true)) {
            return $this->fail([], 'This resource is not available via the generic table API.', 403);
        }

        if ($deny = $this->denyIfPrivateRead($request, $modelSlug)) {
            return $deny;
        }

        $modelClass = $this->getModelClassFromTable($modelSlug);
        if (!$modelClass || !class_exists($modelClass)) {
            return $this->fail([], "Model not found for slug: $modelSlug", 404);
        }

        try {
            $query = $modelClass::query();
            $table = (new $modelClass)->getTable();

            // 1. Dynamic Filtering
            if ($request->filled('query')) {
                $search = trim($request->get('query'));
                if ($search !== '') {
                    $query->where(function ($subQuery) use ($search, $table) {
                        $columns = Schema::getColumnListing($table);
                        $searchable = array_filter($columns, function ($column) use ($table) {
                            return in_array(Schema::getColumnType($table, $column), ['string', 'text', 'char', 'varchar'], true);
                        });

                        foreach ($searchable as $column) {
                            $subQuery->orWhere($column, 'like', "%{$search}%");
                        }

                        if (is_numeric($search) && Schema::hasColumn($table, 'id')) {
                            $subQuery->orWhere('id', $search);
                        }
                    });
                }
            }

            foreach ($request->query() as $column => $value) {
                if (in_array($column, ['with', 'sort', 'page', 'per_page', 'query'], true)) {
                    continue;
                }

                if (Schema::hasColumn($table, $column)) {
                    $query->where($column, $value);
                }
            }

            // 2. Eager Loading (Relationships)
            $this->handleEagerLoading($query, $request);

            // 3. Sorting
            if ($request->has('sort')) {
                $sortParts = explode(',', $request->get('sort'));
                $column = $sortParts[0];
                $direction = $sortParts[1] ?? 'asc';
                if (Schema::hasColumn($table, $column)) {
                    $query->orderBy($column, $direction);
                }
            } else {
                $query->latest(); // Default sort
            }

            // 4. Pagination / get
            if ($request->has('page') || $request->has('per_page')) {
                $perPage = max(1, (int) $request->get('per_page', 10));
                $page = max(1, (int) $request->get('page', 1));
                $records = $query->paginate($perPage, ['*'], 'page', $page);

                $items = $records->items();
                $resourceClass = $this->resolveResourceClass($modelClass);
                if ($resourceClass && count($items) > 0) {
                    $items = $resourceClass::collection(collect($items))->resolve();
                }

                return response()->json([
                    'success' => true,
                    'message' => 'successful',
                    'type' => 'success',
                    'data' => $items,
                    'meta' => [
                        'current_page' => $records->currentPage(),
                        'last_page' => $records->lastPage(),
                        'per_page' => $records->perPage(),
                        'total' => $records->total(),
                        'from' => $records->firstItem(),
                        'to' => $records->lastItem(),
                    ],
                ]);
            }

            $records = $query->get();

            if ($records->isNotEmpty()) {
                $resourceClass = $this->resolveResourceClass($modelClass);
                if ($resourceClass) {
                    return $this->success($resourceClass::collection($records));
                }
            }

            return $this->success($records);
        } catch (Exception $e) {
            Log::error('Universal Get failed', ['error' => $e->getMessage()]);
            return $this->fail([], $e->getMessage(), 500);
        }
    }

    /**
     * Get a single record with eager loading.
     */
    public function universalShow(Request $request, $table, $id)
    {
        if (in_array($table, self::RESTRICTED_TABLES, true)) {
            return $this->fail([], 'This resource is not available via the generic table API.', 403);
        }

        if ($deny = $this->denyIfPrivateRead($request, $table)) {
            return $deny;
        }

        $modelClass = $this->getModelClassFromTable($table);
        if (!class_exists($modelClass)) {
            return $this->fail([], 'Model not found', 404);
        }

        try {
            $query = $modelClass::query();

            // Allow eager loading on single show as well
            $this->handleEagerLoading($query, $request);

            $record = $query->find($id);

            if (!$record) {
                return $this->fail([], 'Record not found', 404);
            }

            $resourceClass = $this->resolveResourceClass($modelClass);
            if ($resourceClass) {
                return $this->success(new $resourceClass($record));
            }

            return $this->success($record->toArray());
        } catch (Exception $e) {
            return $this->fail([], $e->getMessage(), 500);
        }
    }

    /**
     * Massive refactor of the Bulk Create/Update logic to reduce redundancy.
     */
    public static function universalBulkCreateOrUpdate(Request $request, $table)
    {
        $self = new self();

        if (in_array($table, self::RESTRICTED_TABLES, true)) {
            return $self->fail([], 'This resource is not available via the generic table API.', 403);
        }

        // `$table` is the route slug, which for aliases like "vendors" (→
        // App\Models\Vendor, mapped onto the real `providers` table) is not
        // an actual table name — resolve the model's real table before any
        // Schema:: call, the same way universalGet() already does.
        $modelClass = $self->getModelClassFromTable($table);
        $hasModel = $modelClass && class_exists($modelClass);
        $realTable = $hasModel ? (new $modelClass())->getTable() : $table;

        if (!Schema::hasTable($realTable)) {
            return $self->fail([], 'Table not found', 404);
        }

        $items = $request->input('items');
        if (!is_array($items) || empty($items)) {
            return $self->fail([], 'No valid data provided', 400);
        }

        $tableColumns = Schema::getColumnListing($realTable);
        $results = [];

        try {
            Log::info('universalBulkCreateOrUpdate incoming items', ['table' => $table, 'items_sample' => $request->all()['items'] ?? null]);
            DB::beginTransaction();

            foreach ($items as $item) {
                Log::info($item);
                if (!is_array($item)) continue;

                // 1. Prepare Data (Normalize & Clean)
                $data = $self->prepareModelData($item, $tableColumns);
                if (empty($data)) continue;

                $model = null;
                $isUpdate = isset($item['id']) && $item['id'] != 0;

                if ($hasModel) {
                    // 2. Eloquent Handling (Preferred)
                    if ($isUpdate) {
                        $model = $modelClass::find($item['id']);
                        if ($model) {
                            // A plan created by a vendor sync (e.g.
                            // Ogdams::syncPlans()) lands with is_draft=true
                            // so it can't go on sale before an admin reviews
                            // it. Any explicit admin edit through this same
                            // endpoint (the edit form, or "bulk activate") is
                            // exactly that review — clear the flag so the
                            // "Draft" badge doesn't linger forever.
                            if (in_array('is_draft', $tableColumns, true) && $model->is_draft) {
                                $data['is_draft'] = false;
                            }
                            $model->fill($data)->save();
                        } else {
                            // Fallback create if ID sent but not found
                            $model = new $modelClass();
                            $model->forceFill($data); // Force fill incase ID is passed
                            $model->save();
                        }
                    } else {
                        $model = new $modelClass();
                        $model->forceFill($data);
                        $model->save();
                    }

                    // 3. Sync Relationships (The core refactored part)
                    if ($model) {
                        $self->syncModelRelations($model, $item);

                        // Reload relations if needed for response
                        if (method_exists($model, 'networkTypes')) {
                            $model->load('networkTypes');
                        }
                        // Ensure providers (providerable pivot) is included in response so frontend can derive switch state
                        if (method_exists($model, 'providers')) {
                            $model->load('providers');
                        }
                        $results[] = $model;
                    }

                } else {
                    // 4. DB Facade Fallback (No Model)
                    $match = ['id' => $item['id'] ?? 0];
                    DB::table($realTable)->updateOrInsert($match, $data);

                    $results[] = $item['id'] == 0
                        ? DB::table($realTable)->orderByDesc('id')->first()
                        : DB::table($realTable)->find($item['id']);
                }
            }

            DB::commit();

            return $self->success(
                count($items) === 1 ? ($results[0] ?? null) : $results,
                'Operation completed successfully'
            );

        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('Bulk operation failed', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            return $self->fail([], 'Server error: ' . $e->getMessage(), 500);
        }
    }

    public static function universalCreateOrUpdate(Request $request, $table, $id = 0)
    {
        $data = $request->all();
        $data['id'] = $id;
        $request->merge(['items' => [$data]]);
        return self::universalBulkCreateOrUpdate($request, $table);
    }

    /**
     * Reorder records in a table based on provided items.
     * Expects `items` = [{id: 1, sort_order: 1}, ...] and optional `column` (default: sort_order)
     */
    public function reorder(Request $request, $table)
    {
        $modelClass = $this->getModelClassFromTable($table);
        $hasModel = $modelClass && class_exists($modelClass);
        $realTable = $hasModel ? (new $modelClass())->getTable() : $table;

        if (!Schema::hasTable($realTable)) return $this->fail([], 'Table not found', 404);

        $items = $request->input('items');
        if (!is_array($items) || empty($items)) return $this->fail([], 'No items provided', 400);

        $column = $request->input('column', 'sort_order');
        $tableColumns = Schema::getColumnListing($realTable);
        if (!in_array($column, $tableColumns)) return $this->fail([], "Column {$column} not found on table {$table}", 400);

        try {
            DB::beginTransaction();
            foreach ($items as $it) {
                if (!isset($it['id'])) continue;
                $id = $it['id'];
                $value = $it[$column] ?? $it['order'] ?? null;
                if ($value === null) continue;
                DB::table($realTable)->where('id', $id)->update([$column => $value, 'updated_at' => now()]);
            }
            DB::commit();

            $ids = array_column($items, 'id');
            $rows = DB::table($realTable)->whereIn('id', $ids)->orderBy($column)->get();
            return $this->success($rows, 'Reorder completed');
        } catch (Exception $e) {
            DB::rollBack();
            Log::error('Reorder failed', ['error' => $e->getMessage()]);
            return $this->fail([], 'Server error: ' . $e->getMessage(), 500);
        }
    }

    public function universalDelete(Request $request, $table, $id)
    {
        return $this->universalBulkDelete($request->merge(['ids' => [$id]]), $table);
    }

    public function universalBulkDelete(Request $request, $table)
    {
        if (in_array($table, self::RESTRICTED_TABLES, true)) {
            return $this->fail([], 'This resource is not available via the generic table API.', 403);
        }

        $modelClass = $this->getModelClassFromTable($table);
        $hasModel = $modelClass && class_exists($modelClass);
        $realTable = $hasModel ? (new $modelClass())->getTable() : $table;

        if (!Schema::hasTable($realTable)) return $this->fail([], 'Table not found', 404);

        $ids = $request->input('ids');
        if (empty($ids) || !is_array($ids)) return $this->fail([], 'No valid IDs provided', 400);

        try {
            DB::beginTransaction();

            if ($hasModel) {
                // Use Eloquent to ensure events (like pivot detachment) run
                $count = $modelClass::destroy($ids);
            } else {
                $count = DB::table($realTable)->whereIn('id', $ids)->delete();
            }

            DB::commit();

            if ($count > 0) {
                return $this->success(['deleted' => $count], "$count record(s) deleted");
            }
            return $this->fail([], 'No records found to delete', 404);

        } catch (Exception $e) {
            DB::rollBack();
            return $this->fail([], $e->getMessage(), 500);
        }
    }

    // --- Helper Methods ---

    /**
     * Helper to clean input data, handle CamelCase to snake_case, and parse dates.
     */
    private function prepareModelData(array $item, array $tableColumns): array
    {
        // Normalize camelCase to snake_case for common issues
        if (isset($item['serviceType']) && !isset($item['service_type'])) {
            $item['service_type'] = $item['serviceType'];
        }

        // Filter valid columns
        $data = array_filter(
            $item,
            fn($key) => in_array($key, $tableColumns),
            ARRAY_FILTER_USE_KEY
        );

        // Parse Standard Dates
        foreach (['created_at', 'updated_at'] as $date) {
            if (!empty($data[$date])) {
                try {
                    $data[$date] = Carbon::parse($data[$date])->format('Y-m-d H:i:s');
                } catch (Exception $e) {
                    unset($data[$date]);
                }
            }
        }

        return $data;
    }

    /**
     * Centralized Logic for syncing relationships (Providers, NetworkTypes, Pivots).
     */
    /**
 * Centralized Logic for syncing relationships and Morphs.
 * Handles: providerable (morph), networkTypes (many-to-many), etc.
 */
private function syncModelRelations(Model $model, array $item)
{
    // Log entry for tracing relation sync input
    Log::info('syncModelRelations called', ['model' => get_class($model), 'id' => $model->id ?? null, 'item_keys' => array_keys($item)]);

    // 1. Sync Polymorphic "Providerable" (e.g. DataPlan -> Provider)
    if (method_exists($model, 'providers')) {
        // If frontend explicitly indicates not to use providerable, detach any pivot entries
        if (array_key_exists('use_provider_as_providerable', $item) && $item['use_provider_as_providerable'] === false) {
            // Insert or update a providerables row with provider_id = null so the plan
            // is considered "global" (uses NetworkType->provider). Keep default pivot values.
            $serverIdDefault = $item['providerable']['server_id'] ?? $item['server_id'] ?? null;
            $pivotDataDefault = [
                'provider_id' => null,
                'providerable_id' => $model->id,
                'providerable_type' => get_class($model),
                'cost_price' => 0,
                'margin_value' => 0,
                'margin_type' => 'fiat',
                'server_id' => $serverIdDefault,
                'updated_at' => now(),
                'created_at' => now(),
            ];
            try {
                DB::table('providerables')->updateOrInsert(
                    ['providerable_id' => $model->id, 'providerable_type' => get_class($model)],
                    $pivotDataDefault
                );
                Log::info('providerables upserted with provider_id = null due to use_provider_as_providerable=false', ['model' => get_class($model), 'id' => $model->id]);
            } catch (Exception $e) {
                Log::error('Failed to upsert providerables (null provider)', ['error' => $e->getMessage(), 'model' => get_class($model), 'id' => $model->id ?? null]);
            }
        }

        // If providerable payload present, sync the provided provider_id; otherwise do nothing (leave detach as above)
        if (isset($item['providerable'])) {
            $prov = $item['providerable'];
            // Use the top-level toggle to decide whether this plan should use a plan-specific provider
            $provId = (array_key_exists('use_provider_as_providerable', $item) && $item['use_provider_as_providerable']) ? ($prov['provider_id'] ?? null) : null;
            $pivotData = [
                'cost_price' => $prov['cost_price'] ?? 0,
                'margin_value' => $prov['margin_value'] ?? 0,
                'margin_type' => $prov['margin_type'] ?? 'fiat',
                'server_id' => $prov['server_id'] ?? $item['server_id'] ?? null,
            ];

            Log::info('providerable payload', ['model' => get_class($model), 'id' => $model->id ?? null, 'providerable' => $prov]);

            try {
                // Use a single upsert to ensure only one providerables row exists per providerable
                $where = ['providerable_id' => $model->id, 'providerable_type' => get_class($model)];
                $upsert = [
                    'provider_id' => $provId,
                    'cost_price' => $pivotData['cost_price'],
                    'margin_value' => $pivotData['margin_value'],
                    'margin_type' => $pivotData['margin_type'],
                    'server_id' => $pivotData['server_id'] ?? null,
                    'updated_at' => now(),
                    'created_at' => now(),
                ];

                DB::table('providerables')->updateOrInsert($where, $upsert);
                Log::info('providerables upserted (single row) with provider_id', ['model' => get_class($model), 'id' => $model->id, 'provider_id' => $provId, 'pivot' => $upsert]);
            } catch (Exception $e) {
                Log::error('Failed to upsert providerables (single row)', ['error' => $e->getMessage(), 'model' => get_class($model), 'id' => $model->id ?? null]);
            }
        }
    }

    // 2. Sync Many-to-Many "NetworkTypes"
    if (isset($item['network_type_ids']) && is_array($item['network_type_ids']) && method_exists($model, 'networkTypes')) {
        $typeConfig = $item['network_type_config'] ?? [];
        $syncData = [];

        foreach ($item['network_type_ids'] as $typeId) {
            $typeModel = \App\Models\NetworkType::find($typeId);
            $syncData[$typeId] = [
                'service_type' => $typeModel?->service_type ?? 'airtime',
                'active' => $typeConfig[$typeId] ?? true
            ];
        }
        $model->networkTypes()->sync($syncData);
    }

    // 3. Propagate service_type updates to pivot tables
    if (isset($item['service_type']) && $model->getTable() === 'network_types') {
        DB::table('network_network_type')
            ->where('network_type_id', $model->id)
            ->update(['service_type' => $item['service_type'], 'updated_at' => now()]);
    }
}

    private function getModelClassFromTable(string $table): string
    {
        $map = [
            'vendors' => Vendor::class,
            'providers' => Provider::class,
            'data_plans' => \App\Models\DataPlan::class, // Added assumption based on usage
        ];

        if (isset($map[$table])) return $map[$table];

        $name = Str::studly(Str::singular($table));
        return "App\\Models\\{$name}";
    }

    /**
     * Dynamically finds the API Resource for a model.
     */
    private function resolveResourceClass($modelClass): ?string
    {
        // 1. Try generic naming convention: App\Http\Resources\UserResource
        $className = class_basename($modelClass);
        $resourceClass = "App\\Http\\Resources\\{$className}Resource";

        if (class_exists($resourceClass)) {
            return $resourceClass;
        }

        // 2. Fallback to manual map (if naming doesn't align)
        return match ($modelClass) {
            // Add manual overrides here if necessary
            default => null,
        };
    }

    /**
     * Applies 'with' params from request to query builder.
     */
    private function handleEagerLoading($query, Request $request)
    {
        if ($request->has('with')) {
            $relations = explode(',', $request->get('with'));
            foreach ($relations as $relation) {
                // Prevent loading dangerous or non-existent relations if strict mode desired
                // For now, assuming admin trusts inputs or Eloquent will ignore invalid ones gracefully-ish
                try {
                    $query->with(trim($relation));
                } catch (\Exception $e) {
                    // Ignore invalid relations
                }
            }
        }
    }

    // --- Fund & Broadcast (Kept lightweight) ---

    public function fundUser(Request $request, $id)
    {
        $validated = $request->validate([
            'amount' => 'required|numeric|min:0.01',
            'type' => 'required|in:credit,debit',
        ]);

        try {
            $user = User::findOrFail($id);
            TransactionService::fundUser($user, $validated['amount'], $validated['type']);
            return $this->success(['user' => $user->refresh()], "User wallet {$validated['type']}ed successfully");
        } catch (Exception $e) {
            return $this->fail([], $e->getMessage(), 500);
        }
    }

    public function refreshToken(string $id)
    {
        try {
            $vendor = Provider::findOrFail($id);
            $vendor->update(['identifier' => Str::uuid()]);
            return $this->success(['identifier' => $vendor->identifier], 'Token refreshed');
        } catch (Exception $e) {
            return $this->fail([], 'Provider not found', 404);
        }
    }

    /**
     * The provider integrations the "add provider" form can offer, each with
     * its credential-field schema — sourced from VendorFactory so the form
     * always reflects exactly what the code supports.
     */
    public function providerTypes()
    {
        return $this->success(VendorFactory::availableProviders());
    }

    /**
     * Pulls a vendor's live plan catalogue and upserts it into local
     * DataPlan rows (see Ogdams::syncPlans()) so purchases never call the
     * vendor's API just to resolve a plan ID. Not every vendor class
     * implements this — the old per-provider-name-column providers
     * (Adex/SMEPlug/vtpass) don't, since they predate this mechanism.
     */
    public function syncVendorPlans(string $id)
    {
        try {
            $vendor = Vendor::findOrFail($id);
            $vendorInstance = VendorFactory::make($vendor);

            if (!method_exists($vendorInstance, 'syncPlans')) {
                return $this->fail([], 'This provider does not support syncing plans.', 422);
            }

            $summary = $vendorInstance->syncPlans();
            return $this->success($summary, 'Plans synced.');
        } catch (Exception $e) {
            return $this->fail([], $e->getMessage(), 500);
        }
    }

    public function banks(string $id)
    {
        try {
            $provider = Provider::findOrFail($id);
            $gateway = \App\Classes\Payment\PaymentFactory::make($provider);
            $banks = Cache::remember(
                "provider_banks_{$provider->name}",
                now()->addDay(),
                fn() => $gateway->getBanks()
            );

            return $this->success(['banks' => $banks]);
        } catch (Exception $e) {
            return $this->fail([], $e->getMessage(), 500);
        }
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

}
