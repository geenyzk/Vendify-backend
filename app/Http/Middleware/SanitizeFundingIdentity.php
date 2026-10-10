<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/** Keep BVN/NIN out of downstream request logging and exception context. */
final class SanitizeFundingIdentity
{
    public function handle(Request $request, Closure $next)
    {
        $identityNumber = (string) $request->input('id_number', '');

        foreach (['id_number', 'idNumber', 'bvn', 'nin'] as $key) {
            $request->request->remove($key);
            $request->json()->remove($key);
            $request->query->remove($key);
        }

        $request->attributes->set('funding_identity_number', $identityNumber);
        unset($identityNumber);

        try {
            return $next($request);
        } finally {
            $request->attributes->remove('funding_identity_number');
        }
    }
}
