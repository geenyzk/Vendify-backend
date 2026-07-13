<?php

namespace Tests\Unit;

use App\Classes\Payment\PaymentBase;
use App\Models\Provider;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Tests\TestCase;

class PaymentFeeCapTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('providers');
        Schema::create('providers', function (Blueprint $table) {
            $table->id();
            $table->timestamps();
            $table->string('name')->nullable();
            $table->string('category')->nullable();
            $table->string('sub_category')->nullable();
            $table->decimal('charge_fee', 12, 2)->nullable();
            $table->string('charge_type')->nullable();
            $table->decimal('charge_fee_cap', 12, 2)->nullable();
        });
    }

    public function test_charge_fee_cap_limits_the_fee_applied_to_wallet_funding(): void
    {
        $provider = Provider::create([
            'name' => 'flutterwave',
            'category' => 'payment',
            'sub_category' => 'payment',
            'charge_fee' => 2.5,
            'charge_type' => 'percent',
            'charge_fee_cap' => 100,
        ]);

        $payment = new class($provider) extends PaymentBase {
            public function __construct(Provider $provider)
            {
                parent::__construct($provider);
                $this->providerName = 'flutterwave';
            }

            public function generate(User $user): ?array { return null; }
            public function connect(): bool { return true; }
            public function checkBalance(): string { return '0'; }
            protected function getHeaders(): array { return []; }
            protected function formatPayload(array|User $payload, ?User $user = null): array { return []; }
            protected function formatResponse(array $data, User $user): array { return []; }
            protected function callback(Request $request): array { return []; }
            protected function verifyWebhookSignature(Request $request): bool { return true; }

            public function exposedCreditedAmount(float $amount): float
            {
                return $this->creditedAmount($amount);
            }
        };

        $this->assertSame(4900.0, $payment->exposedCreditedAmount(5000));
    }
}
