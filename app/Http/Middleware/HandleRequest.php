<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class HandleRequest
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // return $next($request);
        $this->transformRequest($request);

        /** @var Response $response */
        $response = $next($request);

        // 🔹 Transform the response before sending to browser
        return $this->transformResponse($request, $response);
    }

    protected function transformRequest(Request $request): void
    {
        // Example: add a global request attribute
        $request->attributes->set('request_id', uniqid('req_', true));
    }

    protected function transformResponse(Request $request, Response $response): Response
    {
        if($this->isJsonResponse($response)){
            $data = json_decode($response->getContent(), true);

            $meta = $this->share($request);

            if (is_array($data) && !array_is_list($data)) {
                // Merge meta into the controller's own envelope (message/
                // success/data/type from the HttpResponse trait, or an
                // equivalent plain response()->json([...]) body) instead of
                // wrapping it in another layer. This keeps the real payload
                // exactly one `.data` deep for every endpoint — no more
                // double-wrapping that silently swallowed created-record IDs
                // on the frontend.
                $data['meta'] = $meta;
                $response->setContent(json_encode($data));
            } else {
                // Fallback for the rare response that isn't a JSON object at
                // its top level (a bare list/scalar/null) — nothing to merge
                // meta into, so wrap as before.
                $response->setContent(json_encode([
                    'success' => $response->isSuccessful(),
                    'meta' => $meta,
                    'data' => $data,
                ]));
            }
        }
        return $response;
    }

    // Previously also attached "app" (a full, unfiltered General::find(1) —
    // including bank/BVN fields never meant to leave the admin-only General
    // settings screen) and "auth.user" (the full authenticated User model,
    // with its heavy $appends — transactions, banks, stats, referrals — so
    // every single API response, not just GET /user, carried a duplicate
    // copy of the user's entire transaction history) to EVERY JSON
    // response. Confirmed unused by the frontend (only the real /branding
    // endpoint and GET /user are ever read for this data) and removed: it
    // was an extra DB query plus a real data leak on every request for
    // nothing.
    protected function share(Request $request): array
    {
        return [
            'timestamp' => now()->toDateTimeString(),
            'path' => $request->path(),
            'request_id' => $request->attributes->get('request_id'),
        ];
    }

    protected function isJsonResponse(Response $response):bool{
        return str_contains($response->headers->get('Content-Type'), 'application/json');
    }
}
