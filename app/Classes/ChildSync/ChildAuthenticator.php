<?php

namespace App\Classes\ChildSync;

use App\Models\ChildInstance;
use Illuminate\Http\Request;

/**
 * Shared header-extraction + signature-verification logic for the
 * parent<->child channel. Used both by VerifyChildSignature (the
 * /child/{slug}/directives route group) and WebhookController's 'child'
 * case (the single shared {type}/{identifier} webhook route doesn't fit
 * cleanly into a route-middleware group alongside the payment/vendor
 * cases, which have different auth needs entirely).
 */
class ChildAuthenticator
{
    private const MAX_CLOCK_SKEW_SECONDS = 300;

    /**
     * @return array{0: ?ChildInstance, 1: ?string} [instance, error message]
     */
    public static function verify(Request $request, ?string $slugFallback = null): array
    {
        $slug = $request->header('X-Child-Instance', $slugFallback);
        $timestamp = $request->header('X-Timestamp');
        $signature = $request->header('X-Signature');

        if (!$slug || !$timestamp || !$signature) {
            return [null, 'Missing child auth headers'];
        }

        $instance = ChildInstance::whereSlug($slug)->first();
        if (!$instance || $instance->status !== 'active') {
            return [null, 'Unknown or inactive child instance'];
        }

        if (abs(time() - (int) $timestamp) > self::MAX_CLOCK_SKEW_SECONDS) {
            return [null, 'Stale request timestamp'];
        }

        if (!PayloadSigner::verify($instance->shared_secret, $timestamp, $request->getContent(), $signature)) {
            return [null, 'Invalid signature'];
        }

        $instance->forceFill(['last_seen_at' => now()])->saveQuietly();

        return [$instance, null];
    }
}
