<?php

namespace App;

use App\Support\ErrorMessage;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Throwable;

trait HttpResponse
{
    /**
     * Return a success response.
     */
    public function success($data = null, string $message = "successful", int|string $code = 200, string $type = "success"): JsonResponse
    {
        if (is_string($code)) {
            $type = $code;
            $code = 200;
        }

        // If the client requested a raw response (convention: X-Return-Raw: 1),
        // return the payload directly instead of wrapping it. This helps
        // clients that prefer the created resource to appear at response.data
        // (instead of response.data.data). This is backward-compatible because
        // existing clients that expect the wrapper will continue to get it.
        try {
            $raw = false;
            if (function_exists('request')) {
                $raw = (string) request()->header('X-Return-Raw', '') === '1';
            }
            if ($raw) {
                return response()->json($data, $code);
            }
        } catch (\Throwable $e) {
            // ignore and fall back to wrapped response
        }

        return response()->json([
            'message' => $message,
            'success' => true,
            'data'    => $data,
            'type'    => $type,
        ], $code);
    }

    /**
     * Return a failed response.
     */
    public function fail($errors = null, string $message = "failed", int|string $code = 400, string $type = "error"): JsonResponse
    {
        if (is_string($code)) {
            $type = $code;
            $code = 400;
        }

        return response()->json([
            'message' => $message,
            'success' => false,
            'errors'   => $errors,
            'type'    => $type,
        ], $code);
    }

    /**
     * Fail from a caught exception without leaking internals.
     *
     * The raw exception (SQL, PDO details, class names) goes to the log where
     * developers can find it; the client gets a message a person can act on —
     * "Provider is required." rather than
     * "SQLSTATE[23000]: ... Column 'provider_id' cannot be null (SQL: insert ...)".
     *
     * Use this in every `catch` that returns a response. Domain exceptions that
     * already carry a user-facing message pass through unchanged.
     */
    public function failFromException(Throwable $e, string $context = 'API request failed'): JsonResponse
    {
        Log::error($context, [
            'exception' => get_class($e),
            'error' => $e->getMessage(),
        ]);

        return $this->fail([], ErrorMessage::humanize($e), ErrorMessage::statusFor($e));
    }

    public function redirect(string $url, string $message = "Redirecting", int $code = 200, string $type = "info"): JsonResponse
{
    return response()->json([
        'success' => true,
        'message' => $message,
        'type'    => $type,
        'redirect'=> $url,
    ], $code);
}

}
