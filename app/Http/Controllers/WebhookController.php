<?php

namespace App\Http\Controllers;

use App\Classes\ChildSync\ChildAuthenticator;
use App\Classes\ChildSync\ChildSyncFactory;
use App\Classes\Payment\Payment;
use App\Classes\Vendor\VendorFactory;
use App\Support\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class WebhookController extends Controller
{




    /**
     * Handle payment or vendor webhook
     *
     * This handles webhook requests from payment providers or vendors.
     *@group webhook
     * @urlParam type string required Type of webhook (payment or vendor). Example: payment
     * @urlParam identifier string required Identifier for the webhook. Example: flutterwave
     *
     * @unauthenticated
     *
     * @response 200 {
     *   "status": "success",
     *   "message": "Webhook handled"
     * }
     */
    public function handle(Request $request, string $type, string $identifier)

    {
        // Every inbound webhook is logged the moment it arrives, before any
        // parsing/verification can throw — so "the provider says they sent it
        // but nothing happened" can always be traced to a concrete request.
        Log::info('Webhook received', [
            'type' => $type,
            'identifier' => $identifier,
            'ip' => $request->ip(),
            'has_verif_hash' => $request->hasHeader('verif-hash'),
            'has_signature' => $request->hasHeader('x-monnify-signature') || $request->hasHeader('signature'),
            'content_length' => strlen((string) $request->getContent()),
        ]);

        try {
            switch ($type) {
                case 'payment':
                    return Payment::webhook($request, $identifier) ;

                // The push half of the parent<->child channel: the child
                // uploads customer/transaction snapshots here. This branch
                // was silently dropped by the 2026-07-08 merge, which left
                // ChildSyncFactory with no caller — affiliates never synced.
                case 'child':
                    return $this->childWebhook($request, $identifier);

                case 'vendor':
                default:
                    return VendorFactory::webhook($request, $identifier) ;
            }
        } catch (\Throwable $th) {
            // This catch used to be silent: `//throw $th` with an unreturned
            // fail(), so EVERY webhook exception vanished with no log and no
            // response — the direct cause of "I paid but it never reflected and
            // there's nothing in the logs". Now it records the real error and
            // surfaces it on the Audit Log so a failed credit is always visible.
            Log::error('Webhook handler threw', [
                'type' => $type,
                'identifier' => $identifier,
                'error' => $th->getMessage(),
                'where' => $th->getFile() . ':' . $th->getLine(),
                'trace' => $th->getTraceAsString(),
            ]);

            AuditLogger::record(
                'webhook_error',
                description: "Webhook ({$type}/{$identifier}) failed: " . $th->getMessage(),
                context: [
                    'type' => $type,
                    'identifier' => $identifier,
                    'error' => $th->getMessage(),
                    'where' => $th->getFile() . ':' . $th->getLine(),
                ],
            );

            return $this->fail([], 'Webhook processing error', 500);
        }
    }

    // Same {type}/{identifier} shape as the vendor/payment cases above, but
    // unlike those (which have no payload verification at all), a growing
    // set of less-trusted child instances needs the request signature
    // checked before ChildSyncFactory touches anything.
    protected function childWebhook(Request $request, string $identifier)
    {
        [$instance, $error] = ChildAuthenticator::verify($request, $identifier);
        if (!$instance) {
            return $this->fail([], $error, 401);
        }

        return ChildSyncFactory::webhook($request, $instance);
    }
}
