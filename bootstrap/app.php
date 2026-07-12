<?php

use App\Http\Middleware\AiMonitor;
use App\Http\Middleware\EnsurePermission;
use App\Http\Middleware\EnsureUserType;
use App\Http\Middleware\HandleRequest;
use App\Http\Middleware\TrackLastSeen;
use App\Http\Middleware\VerifyChildSignature;
use App\Http\Middleware\VerifySimDeviceSignature;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        api: __DIR__.'/../routes/api.php',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->statefulApi();
        $middleware->trustProxies(at: '*');

        $middleware->append([
            HandleRequest::class
        ]);

        // Presence heartbeat for the admin "online now" stat — throttled
        // to one users.last_seen_at write per user per minute.
        // AiMonitor piggybacks a platform health sweep on staff traffic
        // (throttled to one sweep per 2 minutes platform-wide) and records
        // problems as AiAlert rows for the admin UI's floating AI button.
        $middleware->api(append: [TrackLastSeen::class, AiMonitor::class]);

        $middleware->alias([
            'user_type' => EnsureUserType::class,
            'permission' => EnsurePermission::class,
            'verify.child.hmac' => VerifyChildSignature::class,
            'verify.sim.hmac' => VerifySimDeviceSignature::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
