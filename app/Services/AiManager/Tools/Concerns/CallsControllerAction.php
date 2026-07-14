<?php

namespace App\Services\AiManager\Tools\Concerns;

use App\Services\AiManager\AiManagerException;
use Illuminate\Http\JsonResponse;

/**
 * Lets an AI tool delegate to an existing HTTP controller method so the real
 * business logic (money movement, payouts, notifications, validation) stays in
 * one place instead of being duplicated — and drifting — inside a tool. The
 * controller reads Auth for the reviewer, which is the same admin whose request
 * is executing the approved action, so audit attribution stays correct.
 */
trait CallsControllerAction
{
    /**
     * Normalise a controller's JsonResponse into the array the model sees,
     * turning any failure envelope or 4xx/5xx into an AiManagerException.
     */
    protected function unwrap(JsonResponse $response, string $failMessage = 'The action could not be completed.'): array
    {
        $payload = $response->getData(true);

        if ($response->getStatusCode() >= 400 || (($payload['success'] ?? true) === false)) {
            throw new AiManagerException($payload['message'] ?? $failMessage);
        }

        return $payload['data'] ?? $payload;
    }
}
