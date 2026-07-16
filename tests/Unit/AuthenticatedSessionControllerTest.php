<?php

namespace Tests\Unit;

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Models\User;
use Tests\TestCase;

class AuthenticatedSessionControllerTest extends TestCase
{
    public function test_auth_user_payload_uses_only_pin_append_for_lightweight_payloads(): void
    {
        $controller = new AuthenticatedSessionController();
        $user = new User();

        $method = new \ReflectionMethod($controller, 'authUserPayload');
        $method->setAccessible(true);

        $result = $method->invoke($controller, $user, false);

        $this->assertSame(['has_pin'], $result->getAppends());
    }
}
