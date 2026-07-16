<?php

namespace App\Http\Controllers;

use App\Classes\VTUServices\VTUServiceFactory;
use App\Models\DataPlan;
use App\Models\User;
use App\Support\ErrorMessage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

/**
 * Route tunneling: this app doubles as an adex-protocol PROVIDER so an
 * affiliate child can be pointed at the parent via a reroute_provider
 * directive — the child's own vending then flows here and the PARENT
 * performs the transaction, funded by the wallet of the parent account
 * whose credentials the child's provider slot holds.
 *
 * Speaks exactly the protocol the child's ApiSending::AdexApi client uses:
 *   1. POST /api/user/  with `Authorization: Basic base64(user:pass)`
 *      -> { "AccessToken": ... }
 *   2. POST /api/data/ | /api/topup/  with `Authorization: Token {AccessToken}`
 *      -> { "status": "success"|"fail"|"process", "response": ... }
 *
 * Idempotency: the child retries stuck transactions with the SAME
 * `request-id`, so each vend is recorded in child_tunnel_requests keyed on
 * (user_id, request_id) and replayed from the ledger instead of re-vended.
 */
class ChildTunnelController extends Controller
{
    private const TOKEN_TTL_SECONDS = 3600;

    // Numeric network ids the adex protocol conventionally uses (matches
    // VendorBase::$networkIDs). Children may also send the name directly.
    private const NETWORK_IDS = [
        '1' => 'mtn',
        '2' => 'airtel',
        '3' => 'glo',
        '4' => '9mobile',
    ];

    public function authenticate(Request $request): JsonResponse
    {
        $user = $this->basicAuthUser($request);
        if (!$user) {
            return response()->json(['status' => 'fail', 'message' => 'Invalid credentials'], 401);
        }

        $token = Crypt::encryptString(json_encode([
            'purpose' => 'child-tunnel',
            'uid' => $user->id,
            'exp' => time() + self::TOKEN_TTL_SECONDS,
        ]));

        // The child's client only requires AccessToken to be present; the
        // extras let a child operator sanity-check whose wallet funds this.
        return response()->json([
            'status' => 'success',
            'AccessToken' => $token,
            'username' => $user->username,
            'balance' => (float) $user->wallet_balance,
        ]);
    }

    public function data(Request $request): JsonResponse
    {
        $user = $this->tokenUser($request);
        if (!$user) {
            return $this->tunnelFail('Invalid or expired access token', 401);
        }

        $planId = $request->input('data_plan');
        $phone = $request->input('phone');
        $requestId = (string) $request->input('request-id', '');

        // Replay BEFORE any validation/plan lookup: the stored outcome must
        // win even if the catalog changed since the original vend.
        if ($replay = $this->replayResponse($user, $requestId)) {
            return $replay;
        }

        if (!$planId || !$phone) {
            return $this->tunnelFail('data_plan and phone are required');
        }

        // The child's data_plan.adex{slot} mapping column must hold THIS
        // app's DataPlan ids for tunneling to work.
        $plan = DataPlan::find($planId);
        if (!$plan) {
            return $this->tunnelFail("Unknown data plan '{$planId}' — map the child's plan ids to this platform's data plan ids");
        }

        $network = strtolower((string) $plan->network);
        $amount = (float) $plan->price;

        return $this->vend($user, $requestId, 'data', [
            'network' => $network,
            'network_type' => $network,
            'plan_type' => $plan->plan_type,
            'data_plan' => $plan->id,
            'phone' => $phone,
            'amount' => $amount,
            'final_amount' => $amount,
        ], fn () => VTUServiceFactory::make('data', $plan->plan_type ?? 'data', $network, $plan->id, $plan->plan_type));
    }

    public function topup(Request $request): JsonResponse
    {
        $user = $this->tokenUser($request);
        if (!$user) {
            return $this->tunnelFail('Invalid or expired access token', 401);
        }

        $rawNetwork = strtolower((string) $request->input('network', ''));
        $network = self::NETWORK_IDS[$rawNetwork] ?? $rawNetwork;
        $phone = $request->input('phone');
        $amount = (float) $request->input('amount', 0);
        $requestId = (string) $request->input('request-id', '');

        if ($replay = $this->replayResponse($user, $requestId)) {
            return $replay;
        }

        if (!in_array($network, ['mtn', 'airtel', 'glo', '9mobile'], true)) {
            return $this->tunnelFail("Unknown network '{$rawNetwork}' — send 1-4 or mtn/airtel/glo/9mobile");
        }
        if (!$phone || $amount <= 0) {
            return $this->tunnelFail('phone and a positive amount are required');
        }

        return $this->vend($user, $requestId, 'airtime', [
            'network' => $network,
            'network_type' => $network,
            'phone' => $phone,
            'amount' => $amount,
            'final_amount' => $amount,
        ], fn () => VTUServiceFactory::make('airtime', $network, $network, null, $network));
    }

    /**
     * Shared vend path: idempotency ledger first, then the exact same
     * handler->process() pipeline the parent's own purchases use (reserve ->
     * vendor call -> record -> refund-on-fail), with the tunnel user as the
     * authenticated wallet owner.
     */
    protected function vend(User $user, string $requestId, string $service, array $payload, \Closure $makeHandler): JsonResponse
    {
        $handler = $makeHandler();
        if (!$handler) {
            return $this->tunnelFail('No vendor configured for this service on the parent');
        }

        // process() reads the wallet owner from Auth — scope it to the
        // tunnel account for this request only (stateless API route).
        Auth::setUser($user);

        try {
            // These vends physically run on the parent but originate from a
            // child platform — record them as affiliate traffic, not the
            // parent's own web/api. The override wraps the whole vend so
            // TransactionService::record() sees it.
            $response = \App\Support\TransactionPlatform::withOverride(
                \App\Support\TransactionPlatform::AFFILIATE,
                fn () => $handler->process($service, $payload),
            );
        } catch (\Throwable $e) {
            Log::error('Child tunnel vend failed', ['service' => $service, 'error' => $e->getMessage()]);
            return $this->tunnelFail(ErrorMessage::humanize($e));
        }

        $body = $response->getData(true);
        $status = match (true) {
            $response->getStatusCode() === 200 => 'success',
            $response->getStatusCode() === 202 => 'process',
            default => 'fail',
        };
        $message = $body['message'] ?? null;

        if ($requestId !== '') {
            try {
                DB::table('child_tunnel_requests')->insert([
                    'user_id' => $user->id,
                    'request_id' => $requestId,
                    'service' => $service,
                    'status' => $status,
                    'response_message' => $message !== null ? mb_substr((string) $message, 0, 500) : null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            } catch (\Throwable $e) {
                // Unique (user_id, request_id) hit by a concurrent duplicate —
                // the vend above already happened; losing the ledger race is
                // harmless, so answer with this vend's own outcome.
                Log::warning('Child tunnel: duplicate ledger insert', ['request_id' => $requestId]);
            }
        }

        // Exactly the shape DataSend/AirtimeSend's status switch expects.
        return response()->json(['status' => $status, 'response' => $message]);
    }

    /**
     * The stored outcome for a request-id this user has already vended —
     * same request-id never charges twice. 'process' rows replay as
     * 'process'; they settle via the transaction's own webhook, not by
     * re-vending.
     */
    protected function replayResponse(User $user, string $requestId): ?JsonResponse
    {
        if ($requestId === '') {
            return null;
        }

        $existing = DB::table('child_tunnel_requests')
            ->where('user_id', $user->id)
            ->where('request_id', $requestId)
            ->first();

        if (!$existing) {
            return null;
        }

        return response()->json([
            'status' => $existing->status,
            'response' => $existing->response_message,
        ]);
    }

    protected function basicAuthUser(Request $request): ?User
    {
        $header = (string) $request->header('Authorization', '');
        if (!str_starts_with($header, 'Basic ')) {
            return null;
        }

        $decoded = base64_decode(substr($header, 6), true);
        if ($decoded === false || !str_contains($decoded, ':')) {
            return null;
        }

        [$username, $password] = explode(':', $decoded, 2);

        $user = User::where('username', $username)->orWhere('email', $username)->first();
        if (!$user || !Hash::check($password, $user->password)) {
            return null;
        }

        return $user;
    }

    protected function tokenUser(Request $request): ?User
    {
        $header = (string) $request->header('Authorization', '');
        foreach (['Token ', 'Bearer '] as $prefix) {
            if (str_starts_with($header, $prefix)) {
                try {
                    $claims = json_decode(Crypt::decryptString(substr($header, strlen($prefix))), true);
                } catch (\Throwable $e) {
                    return null;
                }

                if (($claims['purpose'] ?? null) !== 'child-tunnel' || ($claims['exp'] ?? 0) < time()) {
                    return null;
                }

                return User::find($claims['uid'] ?? null);
            }
        }

        return null;
    }

    protected function tunnelFail(string $message, int $code = 200): JsonResponse
    {
        // The child's client json-decodes the body and switches on `status`
        // regardless of HTTP code — 200 keeps legacy children from treating
        // a definitive rejection as a transport error, except for auth
        // failures where a 401 is the honest signal.
        return response()->json(['status' => 'fail', 'response' => $message], $code);
    }
}
