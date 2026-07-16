<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class ProfilePerformance
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! config('performance.login_profiling') || ! $request->is('api/login')) {
            return $next($request);
        }

        $startedAt = hrtime(true);
        $queries = 0;
        $queryMilliseconds = 0.0;

        DB::listen(function ($query) use (&$queries, &$queryMilliseconds) {
            $queries++;
            $queryMilliseconds += $query->time;
        });

        $response = $next($request);
        $pipelineMilliseconds = round((hrtime(true) - $startedAt) / 1_000_000, 2);

        $existing = $response->headers->get('Server-Timing');
        $timing = 'pipeline;dur=' . $pipelineMilliseconds
            . ', pipeline-db;dur=' . round($queryMilliseconds, 2)
            . ';desc="' . $queries . ' queries"';
        $response->headers->set('Server-Timing', $existing ? $timing . ', ' . $existing : $timing);

        Log::debug('Login middleware performance profile', [
            'pipeline_ms' => $pipelineMilliseconds,
            'query_count' => $queries,
            'query_ms' => round($queryMilliseconds, 2),
        ]);

        return $response;
    }
}
