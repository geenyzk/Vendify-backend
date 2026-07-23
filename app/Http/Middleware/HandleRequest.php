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
        // Do not decode and re-encode every JSON payload merely to attach
        // diagnostics. That doubled peak memory and added work proportional
        // to response size on transaction, customer and catalog lists.
        // Pagination metadata is produced by its controller and remains in
        // the body; request diagnostics belong in cheap response headers.
        if ($this->isJsonResponse($response)) {
            $response->headers->set(
                'X-Request-Id',
                (string) $request->attributes->get('request_id'),
            );
            $response->headers->set('X-Response-Timestamp', now()->toIso8601String());
        }

        return $response;
    }

    protected function isJsonResponse(Response $response):bool{
        return str_contains($response->headers->get('Content-Type'), 'application/json');
    }
}
