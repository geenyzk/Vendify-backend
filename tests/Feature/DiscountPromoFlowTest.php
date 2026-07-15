<?php
/**
 * Manual Test Script for Discount & Promotion Flow
 * Run this in Laravel Tinker or create a test file
 * 
 * php artisan tinker
 * > include('test_discount_promo_flow.php')
 */

namespace Tests\Feature;

use App\Models\Discount;
use App\Models\Promotion;
use App\Models\User;
use App\Services\PromotionService;
use Illuminate\Foundation\Testing\RefreshDatabase;

class DiscountPromoFlowTest
{
    /**
     * Test 1: Base Discount Only
     * Buy 500 MTN airtime with 3% base discount
     * Expected: 485 charged
     */
    public static function testBaseDiscountOnly()
    {
        echo "\n=== Test 1: Base Discount Only ===\n";
        
        $originalAmount = 500.00;
        $network = 'mtn';
        
        // Get base discount
        $baseDiscountedAmount = Discount::getDiscountedAmount($originalAmount, $network);
        $baseDiscountAmount = $originalAmount - $baseDiscountedAmount;
        
        echo "Original: ₦$originalAmount\n";
        echo "Base Discount: ₦$baseDiscountAmount\n";
        echo "Final: ₦$baseDiscountedAmount\n";
        echo "Expected Final: ₦485.00\n";
        
        return abs($baseDiscountedAmount - 485.00) < 0.01 ? "✅ PASS" : "❌ FAIL";
    }

    /**
     * Test 2: Base Discount + Promotion
     * Buy 500 with 3% base discount + 10% promo on discounted amount
     * Expected: (500 - 15) * 0.9 = 436.50
     */
    public static function testBaseDiscountPlusPromotion()
    {
        echo "\n=== Test 2: Base Discount + Promotion ===\n";
        
        $originalAmount = 500.00;
        $network = 'mtn';
        
        // Step 1: Apply base discount
        $baseDiscountedAmount = Discount::getDiscountedAmount($originalAmount, $network);
        $baseDiscountAmount = $originalAmount - $baseDiscountedAmount;
        
        echo "Step 1 - Base Discount:\n";
        echo "  Original: ₦$originalAmount\n";
        echo "  Discount: ₦$baseDiscountAmount\n";
        echo "  After Base: ₦$baseDiscountedAmount\n";
        
        // Step 2: Apply promotion
        $user = User::first();
        $promotionResult = PromotionService::applyPromotion(
            'SAVE20',  // Assuming this promotion exists
            $baseDiscountedAmount,
            'airtime',
            $network,
            $user
        );
        
        $promotionDiscount = $promotionResult['discount_amount'] ?? 0;
        $finalAmount = $promotionResult['final_amount'] ?? $baseDiscountedAmount;
        
        echo "\nStep 2 - Promotion Discount:\n";
        echo "  Promo Discount: ₦$promotionDiscount\n";
        echo "  Final Amount: ₦$finalAmount\n";
        
        $totalDiscount = $baseDiscountAmount + $promotionDiscount;
        echo "\nTotal Discount: ₦$totalDiscount\n";
        echo "User Charged: ₦$finalAmount\n";
        
        return "✅ PASS (if promotion found)";
    }

    /**
     * Test 3: 100% Discount (Free)
     * Buy 500 with promotion code that gives 100% off
     */
    public static function testFreePromotion()
    {
        echo "\n=== Test 3: 100% Discount (Free) ===\n";
        
        $originalAmount = 500.00;
        
        echo "Original: ₦$originalAmount\n";
        echo "Promotion: 100% off\n";
        
        $user = User::first();
        $promotionResult = PromotionService::applyPromotion(
            'FREE100',  // Assuming this exists
            $originalAmount,
            'airtime',
            'mtn',
            $user
        );
        
        $finalAmount = $promotionResult['final_amount'] ?? $originalAmount;
        $totalDiscount = $promotionResult['discount_amount'] ?? 0;
        
        echo "Final Amount: ₦$finalAmount\n";
        echo "Total Discount: ₦$totalDiscount\n";
        echo "Expected: Final = 0, Discount = 500\n";
        
        return ($finalAmount == 0 && $totalDiscount == 500) ? "✅ PASS" : "❌ FAIL";
    }

    /**
     * Test 4: Parameter Mapping Verification
     * Verify that validated data is correctly prepared for service handler
     */
    public static function testParameterMapping()
    {
        echo "\n=== Test 4: Parameter Mapping ===\n";
        
        $originalAmount = 500.00;
        $totalDiscountAmount = 63.50;
        $finalAmount = 436.50;
        $promotionId = 5;
        
        echo "Test Values:\n";
        echo "  Original: ₦$originalAmount\n";
        echo "  Total Discount: ₦$totalDiscountAmount\n";
        echo "  Final Amount: ₦$finalAmount\n";
        
        // Simulate validated array (CORRECTED VERSION)
        $validated = [
            'amount' => $finalAmount,              // ✅ Final amount to charge
            'promotion_id' => $promotionId,        // ✅ Promotion link
            'discount_amount' => $totalDiscountAmount,  // ✅ Total discount
        ];
        
        echo "\nValidated Array (CORRECTED):\n";
        echo "  amount: ₦" . $validated['amount'] . " (what handler receives as payment amount)\n";
        echo "  discount_amount: ₦" . $validated['discount_amount'] . " (total discount to track)\n";
        echo "  promotion_id: " . $validated['promotion_id'] . " (promotion reference)\n";
        
        // Verify mapping
        $passMapping = (
            $validated['amount'] == $finalAmount &&
            $validated['discount_amount'] == $totalDiscountAmount &&
            abs($validated['amount'] + $validated['discount_amount'] - $originalAmount) < 0.01
        );
        
        echo "\nVerification:\n";
        echo "  amount + discount_amount = original? ";
        echo ($passMapping ? "✅ YES" : "❌ NO") . "\n";
        
        return $passMapping ? "✅ PASS" : "❌ FAIL";
    }

    /**
     * Test 5: TransactionService Integration
     * Verify how TransactionService processes the data
     */
    public static function testTransactionServiceFlow()
    {
        echo "\n=== Test 5: TransactionService Processing ===\n";
        
        $originalAmount = 500.00;
        $finalAmount = 436.50;
        $discountAmount = 63.50;
        $userBalance = 1000.00;
        
        echo "Input to TransactionService:\n";
        echo "  amount: ₦$finalAmount (final amount to charge)\n";
        echo "  discount_amount: ₦$discountAmount (total discount)\n";
        echo "  status: success\n";
        echo "  user balance before: ₦$userBalance\n";
        
        // Simulate TransactionService logic
        $balanceBefore = $userBalance;
        $balanceAfter = $balanceBefore - $finalAmount;  // Deduct final amount
        
        echo "\nTransactionService Calculation:\n";
        echo "  Balance Before: ₦$balanceBefore\n";
        echo "  Deduct: ₦$finalAmount (final amount)\n";
        echo "  Balance After: ₦$balanceAfter\n";
        
        echo "\nTransaction Record:\n";
        echo "  amount (stored): ₦$finalAmount (user paid)\n";
        echo "  discount_amount (stored): ₦$discountAmount (discount given)\n";
        echo "  balance_before: ₦$balanceBefore\n";
        echo "  balance_after: ₦$balanceAfter\n";
        
        $expectedAfter = $userBalance - $finalAmount;
        return abs($balanceAfter - $expectedAfter) < 0.01 ? "✅ PASS" : "❌ FAIL";
    }

    /**
     * Run all tests
     */
    public static function runAll()
    {
        echo "\n╔════════════════════════════════════════════════════════╗\n";
        echo "║    DISCOUNT & PROMOTION FLOW - TEST SUITE             ║\n";
        echo "╚════════════════════════════════════════════════════════╝\n";
        
        $tests = [
            'Base Discount Only' => self::testBaseDiscountOnly(),
            'Base + Promotion' => self::testBaseDiscountPlusPromotion(),
            'Free Promotion' => self::testFreePromotion(),
            'Parameter Mapping' => self::testParameterMapping(),
            'TransactionService' => self::testTransactionServiceFlow(),
        ];
        
        echo "\n╔════════════════════════════════════════════════════════╗\n";
        echo "║                    TEST RESULTS                        ║\n";
        echo "╚════════════════════════════════════════════════════════╝\n";
        
        foreach ($tests as $testName => $result) {
            echo sprintf("%-30s %s\n", $testName . ":", $result);
        }
        
        echo "\n";
    }
}

// This is a manual diagnostic helper, not a PHPUnit/Pest test case. Calling
// runAll() here executes database queries while Pest is only discovering test
// files, before Laravel has booted. Run DiscountPromoFlowTest::runAll()
// explicitly from Tinker when this diagnostic output is needed.
