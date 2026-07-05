<?php

namespace App\Http\Middleware;

use App\Models\General;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
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
            Log::info($data);

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

    protected function share(Request $request): array
    {
        return [
            'timestamp' => now()->toDateTimeString(),
            'path' => $request->path(),
            "app" => General::find(1),
            "auth" => [
                'user' => $request->user(),
            ],
            'request_id' => $request->attributes->get('request_id'),
        ];
    }

    protected function isJsonResponse(Response $response):bool{
        return str_contains($response->headers->get('Content-Type'), 'application/json');
    }
}
