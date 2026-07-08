<?php

namespace App\Http\Controllers;

use App\HttpResponse;
use App\Models\Event;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EventController extends Controller
{
    use HttpResponse;

    /**
     * List all Events for the admin management UI.
     */
    public function index(): JsonResponse
    {
        return $this->success(['events' => Event::orderByDesc('id')->get()]);
    }

    /**
     * Show a single Event.
     */
    public function show(Event $event): JsonResponse
    {
        return $this->success(['event' => $event]);
    }

    /**
     * Create a new Event.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate($this->rules());

        $event = Event::create($validated);

        return $this->success(['event' => $event], 'Event created', 201);
    }

    /**
     * Update an existing Event.
     */
    public function update(Request $request, Event $event): JsonResponse
    {
        $validated = $request->validate($this->rules());

        $event->update($validated);

        return $this->success(['event' => $event->fresh()], 'Event updated');
    }

    /**
     * Delete an Event.
     */
    public function destroy(Event $event): JsonResponse
    {
        $event->delete();

        return $this->success(null, 'Event deleted');
    }

    private function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'metric' => 'required|in:referral_count,transaction_volume,transaction_count,wallet_funding_total',
            'service_type' => 'nullable|string|max:255',
            'threshold' => 'required|numeric|min:0.01',
            'repeatable' => 'sometimes|boolean',
            'reward_type' => 'required|in:badge,cash,both',
            'badge_name' => 'nullable|required_if:reward_type,badge,both|string|max:255',
            'badge_icon' => 'nullable|string|max:255',
            'cash_amount' => 'nullable|required_if:reward_type,cash,both|numeric|min:0',
            'active' => 'sometimes|boolean',
        ];
    }
}
