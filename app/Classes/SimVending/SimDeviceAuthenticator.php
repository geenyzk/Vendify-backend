<?php

namespace App\Classes\SimVending;

use App\Classes\ChildSync\PayloadSigner;
use App\Models\SimDevice;
use Illuminate\Http\Request;

/**
 * Header-extraction + signature-verification for the SIM-device channel.
 * Same HMAC scheme as the parent<->child channel (PayloadSigner is shared):
 * X-Sim-Device (slug), X-Timestamp, X-Signature over "{ts}.{rawBody}".
 * Every verified call also counts as a liveness ping (last_seen_at), which
 * is what SimDevice::scopeOnline / job routing key off.
 */
class SimDeviceAuthenticator
{
    private const MAX_CLOCK_SKEW_SECONDS = 300;

    /**
     * @return array{0: ?SimDevice, 1: ?string} [device, error message]
     */
    public static function verify(Request $request): array
    {
        $slug = $request->header('X-Sim-Device');
        $timestamp = $request->header('X-Timestamp');
        $signature = $request->header('X-Signature');

        if (!$slug || !$timestamp || !$signature) {
            return [null, 'Missing sim device auth headers'];
        }

        $device = SimDevice::whereSlug($slug)->first();
        if (!$device || $device->status !== 'active' || !$device->shared_secret) {
            return [null, 'Unknown or inactive sim device'];
        }

        if (abs(time() - (int) $timestamp) > self::MAX_CLOCK_SKEW_SECONDS) {
            return [null, 'Stale request timestamp'];
        }

        if (!PayloadSigner::verify($device->shared_secret, $timestamp, $request->getContent(), $signature)) {
            return [null, 'Invalid signature'];
        }

        $device->forceFill(['last_seen_at' => now()])->saveQuietly();

        return [$device, null];
    }
}
