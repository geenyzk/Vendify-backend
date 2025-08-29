<?php

namespace App;

use Illuminate\Http\JsonResponse;

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
