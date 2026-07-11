<?php

namespace App\Http\Middleware;

use App\Classes\SimVending\SimDeviceAuthenticator;
use Closure;
use Illuminate\Http\Request;

class VerifySimDeviceSignature
{
    public function handle(Request $request, Closure $next)
    {
        [$device, $error] = SimDeviceAuthenticator::verify($request);
        if (!$device) {
            return response()->json(['message' => $error], 401);
        }

        // Downstream controllers resolve the device via $request->attributes
        // rather than re-querying by slug.
        $request->attributes->set('simDevice', $device);

        return $next($request);
    }
}
