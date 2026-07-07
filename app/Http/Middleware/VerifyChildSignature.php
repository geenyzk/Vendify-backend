<?php

namespace App\Http\Middleware;

use App\Class\ChildSync\ChildAuthenticator;
use Closure;
use Illuminate\Http\Request;

class VerifyChildSignature
{
    public function handle(Request $request, Closure $next)
    {
        [$instance, $error] = ChildAuthenticator::verify($request);
        if (!$instance) {
            return response()->json(['message' => $error], 401);
        }

        // Downstream controllers resolve the instance via $request->attributes
        // rather than re-querying by slug.
        $request->attributes->set('childInstance', $instance);

        return $next($request);
    }
}
