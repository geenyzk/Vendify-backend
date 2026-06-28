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

            $response->setContent(json_encode([
                'success' => $response->isSuccessful(),
                'meta' => $this->share($request),
                'data' => $data,
            ]));
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
