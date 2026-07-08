<?php

namespace App\Http\Controllers;

use App\HttpResponse;
use App\Models\Promotion;
use App\Models\Transaction;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

class PromotionController extends Controller
{
    use HttpResponse;

    /**
     * List all promo codes for the admin management UI.
     */
    public function index(): JsonResponse
    {
        return $this->success(['promotions' => Promotion::orderByDesc('id')->get()]);
    }

    /**
     * Show a single promo code.
     */
    public function show(Promotion $promotion): JsonResponse
    {
        return $this->success(['promotion' => $promotion]);
    }

    /**
     * Create a new promo code.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate($this->rules());

        if (!empty($validated['code'])) {
            $validated['code'] = strtoupper($validated['code']);
        }

        $promotion = Promotion::create($validated);

        return $this->success(['promotion' => $promotion], 'Promotion created', 201);
    }

    /**
     * Update an existing promo code.
     */
    public function update(Request $request, Promotion $promotion): JsonResponse
    {
        $validated = $request->validate($this->rules($promotion->id));

        if (array_key_exists('code', $validated) && $validated['code'] !== null) {
            $validated['code'] = strtoupper($validated['code']);
        }

        $promotion->update($validated);

        return $this->success(['promotion' => $promotion->fresh()], 'Promotion updated');
    }

    /**
     * Delete a promo code.
     */
    public function destroy(Promotion $promotion): JsonResponse
    {
        $promotion->delete();

        return $this->success(null, 'Promotion deleted');
    }

    /**
     * Shared validation rules for store/update. $ignoreId lets update()
     * exclude the current row from the code-uniqueness check.
     */
    private function rules(?int $ignoreId = null): array
    {
        return [
            'name' => 'required|string|max:255',
            'code' => [
                'nullable', 'string', 'max:50', 'required_if:apply,code',
                Rule::unique('promotions', 'code')->ignore($ignoreId),
            ],
            'apply' => 'required|in:auto,code',
            'target' => 'required|in:customer,reseller,both',
            'product' => 'required|in:airtime,data,bundle',
            'provider' => 'nullable|string|max:255',
            'type' => 'required|in:percentage,fixed,bonus_data,cashback',
            'value' => 'required|numeric|min:0',
            'active' => 'sometimes|boolean',
            'starts_at' => 'nullable|date',
            'ends_at' => 'nullable|date|after_or_equal:starts_at',
            'usage_limit_total' => 'nullable|integer|min:1',
            'usage_limit_per_customer' => 'nullable|integer|min:1',
            'conditions' => 'nullable|array',
            'conditions.*.kind' => 'required|string',
            // Each condition kind carries its own extra key (see
            // PromotionController::validateCondition) — list them all so
            // $request->validate() doesn't silently strip them.
            'conditions.*.amount' => 'nullable|numeric',
            'conditions.*.provider' => 'nullable|string',
            'conditions.*.product' => 'nullable|string',
            'conditions.*.tier' => 'nullable|string',
            'conditions.*.from' => 'nullable|string',
            'conditions.*.to' => 'nullable|string',
        ];
    }

    /**
     * Validate a promo code and return applicable discount details
     *
     * @authenticated
     *
     * @bodyParam code string required The promo code to validate. Example: MTN20
     * @bodyParam product string required The product type (airtime, data, bundle). Example: airtime
     * @bodyParam provider string The network provider (MTN, AIRTEL, GLO, 9MOBILE). Example: MTN
     * @bodyParam amount numeric The transaction amount for min_amount validation. Example: 500
     *
     * @response 200 {
     *    "status": "success",
     *    "data": {
     *       "promotion": {
     *          "id": 1,
     *          "name": "MTN Data Bonus 20%",
     *          "code": "MTN20",
     *          "type": "percentage",
     *          "value": 20,
     *          "discount_amount": 100
     *       },
     *       "message": "Promo code applied successfully"
     *    }
     * }
     *
     * @response 422 {
     *    "status": "error",
     *    "message": "Invalid or expired promo code",
     *    "code": 422
     * }
     */
    public function validate(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'code' => 'required|string|max:50',
            'product' => 'required|string|in:airtime,data,bundle',
            'provider' => 'nullable|string',
            'amount' => 'nullable|numeric|min:0',
        ]);

        Log::info($validated);
        $user = Auth::user();

        // Carbon::parse(now());

        // Find the promotion by code
        $promotion = Promotion::where('code', strtoupper($validated['code']))
            ->where('apply', 'code')
            ->where('active', true)
            ->first();

        if (!$promotion) {
            return response()->json([
                'status' => 'error',
                'message' => 'Invalid or expired promo code',
            ], 422);
        }

        // Check if promotion is currently active (date range + usage limits)
        if (!$promotion->isCurrentlyActive()) {
            return response()->json([
                'status' => 'error',
                'message' => 'This promo code is no longer valid',
            ], 422);
        }

        // Check if user is eligible (target)
        if (!$this->isUserEligible($promotion, $user)) {
            return response()->json([
                'status' => 'error',
                'message' => 'You are not eligible for this promo code',
            ], 422);
        }

        // Check if product matches
        if ($promotion->product !== $validated['product']) {
            return response()->json([
                'status' => 'error',
                'message' => "This promo code is only valid for {$promotion->product}",
            ], 422);
        }

        // Check if provider matches (if specified)
        if ($promotion->provider && $promotion->provider !== 'All' && $promotion->provider !== $validated['provider']) {
            return response()->json([
                'status' => 'error',
                'message' => "This promo code is only valid for {$promotion->provider}",
            ], 422);
        }

        // Check usage per customer limit
        if ($promotion->usage_limit_per_customer !== null) {
            $usageCount = Transaction::where('user_id', $user->id)
                ->where('promotion_id', $promotion->id)
                ->count();

            if ($usageCount >= $promotion->usage_limit_per_customer) {
                return response()->json([
                    'status' => 'error',
                    'message' => "You have reached the usage limit for this promo code",
                ], 422);
            }
        }

        // Validate conditions
        if (!$this->validateConditions($promotion, $validated)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Transaction does not meet promo code requirements',
            ], 422);
        }

        // Calculate discount
        $amount = $validated['amount'] ?? 0;
        $discountAmount = $this->calculateDiscount($promotion, $amount);

        return response()->json([
            'status' => 'success',
            'data' => [
                'promotion' => [
                    'id' => $promotion->id,
                    'name' => $promotion->name,
                    'code' => $promotion->code,
                    'type' => $promotion->type,
                    'value' => $promotion->value,
                    'discount_amount' => $discountAmount,
                ],
                'message' => 'Promo code applied successfully',
            ],
        ], 200);
    }

    /**
     * Apply a promo code to a transaction
     *
     * @authenticated
     *
     * @bodyParam code string required The promo code. Example: MTN20
     * @bodyParam transaction_id numeric The transaction ID to attach promotion to. Example: 123
     *
     * @response 200 {
     *    "status": "success",
     *    "message": "Promo code applied",
     *    "promotion_id": 1
     * }
     */
    public function apply(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'code' => 'required|string|max:50',
            'transaction_id' => 'nullable|numeric',
        ]);

        $promotion = Promotion::where('code', strtoupper($validated['code']))
            ->where('active', true)
            ->first();

        if (!$promotion) {
            return response()->json([
                'status' => 'error',
                'message' => 'Promo code not found',
            ], 422);
        }

        // Increment usage
        $promotion->incrementUsage();

        if ($validated['transaction_id']) {
            Transaction::find($validated['transaction_id'])?->update([
                'promotion_id' => $promotion->id,
            ]);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Promo code applied',
            'promotion_id' => $promotion->id,
        ], 200);
    }

    /**
     * Check if user is eligible for the promotion
     */
    private function isUserEligible(Promotion $promotion, $user): bool
    {
        $target = $promotion->target;

        if ($target === 'customer') {
            return $user->user_type === 'customer' || $user->user_type === 'user';
        }

        if ($target === 'reseller') {
            return in_array($user->user_type, ['reseller', 'agent', 'admin']);
        }

        return true; // both
    }

    /**
     * Validate promotion conditions
     */
    private function validateConditions(Promotion $promotion, array $data): bool
    {
        if (!$promotion->conditions || empty($promotion->conditions)) {
            return true; // No conditions = always valid
        }

        foreach ($promotion->conditions as $condition) {
            if (!$this->validateCondition($condition, $data)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Validate a single condition
     */
    private function validateCondition(array $condition, array $data): bool
    {
        $kind = $condition['kind'] ?? null;

        return match($kind) {
            'min_amount' => ($data['amount'] ?? 0) >= ($condition['amount'] ?? 0),
            'max_amount' => ($data['amount'] ?? PHP_INT_MAX) <= ($condition['amount'] ?? PHP_INT_MAX),
            'provider' => ($data['provider'] ?? null) === $condition['provider'],
            'product' => ($data['product'] ?? null) === $condition['product'],
            'first_time' => true, // Would need additional logic to check if first transaction
            'recurring_topup' => true, // Would need transaction history check
            'reseller_tier' => true, // Would need user tier check
            'time_window' => $this->isInTimeWindow($condition),
            default => true,
        };
    }

    /**
     * Check if current time is in the specified window
     */
    private function isInTimeWindow(array $condition): bool
    {
        $from = $condition['from'] ?? '00:00';
        $to = $condition['to'] ?? '23:59';
        $now = now()->format('H:i');

        return $now >= $from && $now <= $to;
    }

    /**
     * Calculate the discount amount based on promotion type
     */
    private function calculateDiscount(Promotion $promotion, float $amount): float
    {
        return match($promotion->type) {
            'percentage' => ($promotion->value / 100) * $amount,
            'fixed' => min($promotion->value, $amount),
            'bonus_data' => $promotion->value, // MB bonus (not monetary)
            'cashback' => $promotion->value,
            default => 0,
        };
    }
}
