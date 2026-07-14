<?php

namespace Tests\Unit;

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Models\User;
use Tests\TestCase;

class AuthenticatedSessionControllerTest extends TestCase
{
    public function test_auth_user_payload_keeps_banks_append_for_lightweight_payloads(): void
    {
        $controller = new AuthenticatedSessionController();
        $user = new User();

        $method = new \ReflectionMethod($controller, 'authUserPayload');
        $method->setAccessible(true);

        $result = $method->invoke($controller, $user, false);

        $this->assertContains('banks', $result->getAppends());
        $this->assertContains('has_pin', $result->getAppends());
    }
}
