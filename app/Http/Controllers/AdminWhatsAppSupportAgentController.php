<?php

namespace App\Http\Controllers;

use App\HttpResponse;
use App\Models\User;
use App\Models\WhatsAppSupportAgent;
use App\Support\AuditLogger;
use App\Support\WhatsAppPhoneNumber;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

class AdminWhatsAppSupportAgentController extends Controller
{
    use HttpResponse;

    public function index(Request $request): JsonResponse
    {
        $request->validate(['per_page' => 'nullable|integer|min:1|max:100']);
        $agents = WhatsAppSupportAgent::query()->withCount('assignments')->orderBy('sort_order')->orderBy('id')->paginate($request->integer('per_page', 25));
        return response()->json(['message' => 'successful', 'success' => true, 'data' => $agents->items(), 'meta' => [
            'current_page' => $agents->currentPage(), 'last_page' => $agents->lastPage(),
            'per_page' => $agents->perPage(), 'total' => $agents->total(),
        ], 'type' => 'success']);
    }

    public function store(Request $request): JsonResponse
    {
        if ($error = $this->normalizePhone($request)) return $error;
        $data = $this->validateAgent($request);
        if ($error = $this->validateLinkedUser($data['linked_user_id'] ?? null)) return $error;
        $agent = WhatsAppSupportAgent::create($data + ['created_by' => $request->user()->id]);
        AuditLogger::record('whatsapp_support_agent_created', subject: $agent, description: "WhatsApp support agent {$agent->display_name} was created.");
        return $this->success($agent, 'WhatsApp support agent created.', 201);
    }

    public function update(Request $request, WhatsAppSupportAgent $agent): JsonResponse
    {
        if ($request->has('phone_number') && ($error = $this->normalizePhone($request))) return $error;
        $data = $this->validateAgent($request, $agent);
        if (array_key_exists('linked_user_id', $data) && ($error = $this->validateLinkedUser($data['linked_user_id']))) return $error;
        $before = $agent->only(array_keys($data));
        $agent->update($data);
        AuditLogger::record('whatsapp_support_agent_updated', subject: $agent, changes: ['before' => $before, 'after' => $agent->fresh()->only(array_keys($data))]);
        return $this->success($agent->fresh(), 'WhatsApp support agent updated.');
    }

    public function availability(Request $request, WhatsAppSupportAgent $agent): JsonResponse
    {
        $data = $request->validate(['availability' => ['required', Rule::in(WhatsAppSupportAgent::AVAILABILITIES)]]);
        $before = $agent->availability;
        $agent->update($data);
        AuditLogger::record('whatsapp_support_availability_changed', subject: $agent, changes: ['availability' => ['old' => $before, 'new' => $agent->availability]]);
        return $this->success($agent, 'Availability updated.');
    }

    public function destroy(WhatsAppSupportAgent $agent): JsonResponse
    {
        DB::transaction(function () use ($agent) {
            $agent->update(['enabled' => false, 'availability' => 'offline']);
            $agent->delete();
        });
        AuditLogger::record('whatsapp_support_agent_deleted', subject: $agent, description: "WhatsApp support agent {$agent->display_name} was deactivated and deleted.");
        return $this->success(null, 'WhatsApp support agent deleted.');
    }

    private function validateAgent(Request $request, ?WhatsAppSupportAgent $agent = null): array
    {
        return $request->validate([
            'display_name' => [$agent ? 'sometimes' : 'required', 'string', 'max:120'],
            'phone_number' => [$agent ? 'sometimes' : 'required', 'string', 'max:16', Rule::unique('whatsapp_support_agents', 'phone_number')->ignore($agent?->id)],
            'enabled' => 'sometimes|boolean',
            'availability' => ['sometimes', Rule::in(WhatsAppSupportAgent::AVAILABILITIES)],
            'sort_order' => 'sometimes|integer|min:0|max:100000',
            'linked_user_id' => 'nullable|integer|exists:users,id',
            'department' => 'nullable|string|max:80',
            'notes' => 'nullable|string|max:2000',
        ]);
    }

    private function normalizePhone(Request $request): ?JsonResponse
    {
        try {
            $request->merge(['phone_number' => WhatsAppPhoneNumber::normalize((string) $request->input('phone_number'))]);
            return null;
        } catch (InvalidArgumentException $e) {
            return $this->fail(['phone_number' => [$e->getMessage()]], 'Validation failed.', 422);
        }
    }

    private function validateLinkedUser(?int $id): ?JsonResponse
    {
        if (!$id) return null;
        $eligible = User::whereKey($id)->whereHas('role', fn ($role) => $role->where('is_staff', true)->where('is_active', true))->exists();
        return $eligible ? null : $this->fail(['linked_user_id' => ['The linked user must be active staff.']], 'Validation failed.', 422);
    }
}
