<?php

namespace App\Http\Controllers;

use App\HttpResponse;
use App\Services\WhatsAppSupportRoutingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use RuntimeException;

class WhatsAppSupportController extends Controller
{
    use HttpResponse;

    public function route(Request $request, WhatsAppSupportRoutingService $router): JsonResponse
    {
        $data = $request->validate([
            'ticket_id' => 'nullable|integer|min:1',
            'transaction_id' => 'nullable|integer|min:1',
        ]);

        try {
            return $this->success($router->route(
                $request->user(),
                $data['ticket_id'] ?? null,
                $data['transaction_id'] ?? null,
            ));
        } catch (InvalidArgumentException $e) {
            return $this->fail([], $e->getMessage(), 422);
        } catch (RuntimeException $e) {
            return response()->json([
                'message' => $e->getMessage(), 'success' => false, 'errors' => null,
                'type' => 'error', 'code' => 'WHATSAPP_SUPPORT_UNAVAILABLE',
            ], 503);
        }
    }
}
