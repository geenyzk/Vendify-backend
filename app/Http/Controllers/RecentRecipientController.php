<?php

namespace App\Http\Controllers;

use App\HttpResponse;
use App\Models\RecentRecipient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class RecentRecipientController extends Controller
{
    use HttpResponse;

    public function index(Request $request): JsonResponse
    {
        $recipients = $request->user()->recentRecipients()
            ->orderByDesc('last_used_at')
            ->orderByDesc('id')
            ->limit(RecentRecipient::MAX_PER_USER)
            ->get(['id', 'phone', 'last_used_at']);

        return $this->success($recipients);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            // Buy Airtime and Buy Data both accept Nigerian local numbers.
            'phone' => ['required', 'string', 'regex:/^0[0-9]{10}$/'],
        ]);

        $phone = $this->normalize($validated['phone']);

        $recipient = DB::transaction(function () use ($request, $phone) {
            $recipient = $request->user()->recentRecipients()->updateOrCreate(
                ['phone' => $phone],
                ['last_used_at' => now()],
            );

            $idsToKeep = $request->user()->recentRecipients()
                ->orderByDesc('last_used_at')
                ->orderByDesc('id')
                ->limit(RecentRecipient::MAX_PER_USER)
                ->pluck('id');

            $request->user()->recentRecipients()
                ->whereNotIn('id', $idsToKeep)
                ->delete();

            return $recipient->fresh();
        });

        return $this->success($recipient->only(['id', 'phone', 'last_used_at']), 'Recent number saved');
    }

    public function destroy(Request $request, RecentRecipient $recentRecipient): JsonResponse
    {
        abort_unless($recentRecipient->user_id === $request->user()->id, 404);
        $recentRecipient->delete();

        return $this->success(null, 'Recent number removed');
    }

    public function clear(Request $request): JsonResponse
    {
        $request->user()->recentRecipients()->delete();

        return $this->success(null, 'Recent numbers cleared');
    }

    private function normalize(string $phone): string
    {
        return preg_replace('/\D/', '', $phone);
    }
}
