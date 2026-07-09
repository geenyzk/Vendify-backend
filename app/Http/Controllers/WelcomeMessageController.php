<?php

namespace App\Http\Controllers;

use App\Models\WelcomeMessage;
use App\Models\WelcomeMessageView;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class WelcomeMessageController extends Controller
{
    /**
     * Customer-facing: the active welcome message, plus whether the
     * authenticated user has already seen the current version of it.
     */
    public function show(): JsonResponse
    {
        $message = WelcomeMessage::where('active', true)->first();

        if (!$message) {
            return $this->success(['welcome_message' => null, 'seen' => false]);
        }

        $seen = WelcomeMessageView::where('user_id', Auth::id())
            ->where('welcome_message_id', $message->id)
            ->where('seen_at', '>=', $message->updated_at)
            ->exists();

        return $this->success([
            'welcome_message' => $message,
            'seen' => $seen,
        ]);
    }

    /**
     * Admin: the configured welcome message regardless of `active`, so it
     * can be edited even while turned off.
     */
    public function adminShow(): JsonResponse
    {
        return $this->success(['welcome_message' => WelcomeMessage::first()]);
    }

    /**
     * Admin: create or update the single welcome message record.
     */
    public function upsert(Request $request): JsonResponse
    {
        // Title is no longer part of the UI — only the body is shown to
        // users. Still accepted (optional) for backward compatibility.
        $validated = $request->validate([
            'title' => 'nullable|string|max:255',
            'body' => 'required|string',
            'active' => 'boolean',
        ]);

        $message = WelcomeMessage::first();

        if ($message) {
            $message->update($validated);
        } else {
            // The legacy `title` column may still be NOT NULL on databases
            // that haven't run the nullable migration — default it to '' so
            // a create never trips the constraint.
            $message = WelcomeMessage::create(array_merge(['active' => true, 'title' => ''], $validated));
        }

        return $this->success(['welcome_message' => $message], 'Welcome message updated');
    }

    /**
     * Admin: remove the configured welcome message entirely.
     */
    public function destroy(): JsonResponse
    {
        WelcomeMessage::query()->delete();

        return $this->success(null, 'Welcome message removed');
    }

    /**
     * Record that the authenticated user has seen the current welcome message.
     */
    public function markSeen(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'welcome_message_id' => 'required|integer|exists:welcome_messages,id',
        ]);

        WelcomeMessageView::updateOrCreate(
            [
                'user_id' => Auth::id(),
                'welcome_message_id' => $validated['welcome_message_id'],
            ],
            ['seen_at' => now()]
        );

        return $this->success(null, 'Marked as seen');
    }
}
