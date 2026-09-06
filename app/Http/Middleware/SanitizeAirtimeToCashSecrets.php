<?php

namespace App\Http\Middleware;

use App\Services\AirtimeToCash\RequestSecrets;
use Closure;
use Illuminate\Http\Request;

/** Runs before request instrumentation. Secrets are never flashed or left in request JSON. */
final class SanitizeAirtimeToCashSecrets
{
    public function handle(Request $request, Closure $next)
    {
        $input = $request->all();
        $secrets = new RequestSecrets(['otp' => $input['otp'] ?? null, 'pin' => $input['pin'] ?? null]);
        $safe = $this->strip($input);
        $request->replace($safe);
        foreach (['otp', 'pin', 'transfer_pin'] as $key) {
            $request->json()->remove($key);
            $request->query->remove($key);
        }
        $request->attributes->set('atc_secrets', $secrets);
        unset($input);
        try {
            return $next($request);
        } finally {
            $secrets->clear();
            $request->attributes->remove('atc_secrets');
        }
    }

    private function strip(array $data): array
    {
        foreach ($data as $key => $value) {
            if (preg_match('/pin|otp|token|secret|password|session.?id|identifier/i', (string) $key)) {
                unset($data[$key]);
            } elseif (is_array($value)) {
                $data[$key] = $this->strip($value);
            }
        }

        return $data;
    }
}
