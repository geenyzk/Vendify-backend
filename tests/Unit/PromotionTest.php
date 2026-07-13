<?php

namespace Tests\Unit;

use App\Models\Promotion;
use Tests\TestCase;

class PromotionTest extends TestCase
{
    public function test_promotion_supports_multiple_products(): void
    {
        $promotion = new Promotion([
            'product' => 'airtime',
            'products' => ['airtime', 'data'],
        ]);

        $this->assertTrue($promotion->appliesToProduct('airtime'));
        $this->assertTrue($promotion->appliesToProduct('data'));
        $this->assertFalse($promotion->appliesToProduct('bundle'));
    }

    public function test_promotion_falls_back_to_single_product_when_products_are_missing(): void
    {
        $promotion = new Promotion([
            'product' => 'bundle',
            'products' => null,
        ]);

        $this->assertTrue($promotion->appliesToProduct('bundle'));
        $this->assertFalse($promotion->appliesToProduct('data'));
    }
}
