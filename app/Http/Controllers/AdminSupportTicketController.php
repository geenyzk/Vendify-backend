<?php

namespace App\Http\Controllers;

use App\HttpResponse;
use App\Models\SupportTicket;
use App\Models\User;
use App\Notifications\SupportTicketNotification;
use App\Services\SupportTicketPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class AdminSupportTicketController extends Controller
{
    use HttpResponse;

    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'status' => ['nullable', Rule::in(SupportTicket::STATUSES)],
            'priority' => ['nullable', Rule::in(SupportTicket::PRIORITIES)],
            'assignment' => ['nullable', Rule::in(['all', 'unassigned', 'mine'])],
            'search' => 'nullable|string|max:120', 'per_page' => 'nullable|integer|min:1|max:100',
        ]);

        $query = SupportTicket::with(['user:id,fullname,username,email,phone,status,is_active,created_at,wallet_balance', 'transaction', 'assignee:id,fullname,username'])->latest('updated_at');
        if ($request->filled('status')) $query->where('status', $request->string('status'));
        if ($request->filled('priority')) $query->where('priority', $request->string('priority'));
        if ($request->input('assignment') === 'unassigned') $query->whereNull('assigned_to');
        if ($request->input('assignment') === 'mine') $query->where('assigned_to', $request->user()->id);
        if ($search = trim((string) $request->input('search'))) {
            $query->where(function ($q) use ($search) {
                $q->where('reference', 'like', "%{$search}%")
                    ->orWhereHas('user', fn ($user) => $user->where('fullname', 'like', "%{$search}%")->orWhere('username', 'like', "%{$search}%")->orWhere('email', 'like', "%{$search}%")->orWhere('phone', 'like', "%{$search}%"))
                    ->orWhereHas('transaction', fn ($transaction) => $transaction->where('transaction_reference', 'like', "%{$search}%"));
            });
        }
        $tickets = $query->paginate($request->integer('per_page', 20));
        return $this->paginated($tickets, fn ($ticket) => SupportTicketPresenter::base($ticket) + ['customer' => SupportTicketPresenter::customer($ticket->user)]);
    }

    public function show(SupportTicket $ticket): JsonResponse
    {
        $ticket->load(['user', 'transaction', 'assignee', 'messages.sender', 'notes.author']);
        $recent = SupportTicket::with(['transaction', 'assignee'])->where('user_id', $ticket->user_id)->where('id', '!=', $ticket->id)->latest()->limit(5)->get()->map(fn ($item) => SupportTicketPresenter::base($item));
        return $this->success(SupportTicketPresenter::adminDetail($ticket) + ['recent_tickets' => $recent]);
    }

    public function reply(Request $request, SupportTicket $ticket): JsonResponse
    {
        $data = $request->validate(['message' => 'required|string|min:1|max:10000']);
        if ($ticket->status === 'closed') return $this->fail([], 'Closed tickets cannot receive replies.', 409);
        $message = DB::transaction(function () use ($request, $ticket, $data) {
            $message = $ticket->messages()->create(['sender_id' => $request->user()->id, 'sender_role' => 'staff', 'message' => $data['message']]);
            if ($ticket->status === 'open') $ticket->update(['status' => 'in_review']); else $ticket->touch();
            return $message;
        });
        $ticket->user->notify(new SupportTicketNotification($ticket->fresh(), 'admin_replied'));
        return $this->success(SupportTicketPresenter::message($message->load('sender')), 'Reply added.', 201);
    }

    public function note(Request $request, SupportTicket $ticket): JsonResponse
    {
        $data = $request->validate(['note' => 'required|string|min:1|max:10000']);
        $note = $ticket->notes()->create(['author_id' => $request->user()->id, 'note' => $data['note']]);
        $note->load('author');
        return $this->success(['id' => $note->id, 'note' => $note->note, 'author' => ['id' => $note->author_id, 'name' => $note->author->fullname ?: $note->author->username], 'created_at' => $note->created_at], 'Internal note added.', 201);
    }

    public function status(Request $request, SupportTicket $ticket): JsonResponse
    {
        $data = $request->validate(['status' => ['required', Rule::in(SupportTicket::STATUSES)]]);
        $next = $data['status'];
        if ($next !== $ticket->status && !in_array($next, SupportTicket::STATUS_TRANSITIONS[$ticket->status] ?? [], true)) {
            return $this->fail(['status' => ["Cannot transition from {$ticket->status} to {$next}."]], 'Invalid status transition.', 422);
        }
        $updates = ['status' => $next];
        if ($next === 'resolved') $updates['resolved_at'] = now();
        elseif ($ticket->status === 'resolved') $updates['resolved_at'] = null;
        if ($next === 'closed') $updates['closed_at'] = now();
        $ticket->update($updates);
        $ticket->user->notify(new SupportTicketNotification($ticket, $next === 'resolved' ? 'resolved' : 'status_changed'));
        return $this->success(SupportTicketPresenter::base($ticket->fresh(['transaction', 'assignee'])), 'Ticket status updated.');
    }

    public function priority(Request $request, SupportTicket $ticket): JsonResponse
    {
        $data = $request->validate(['priority' => ['required', Rule::in(SupportTicket::PRIORITIES)]]);
        $ticket->update($data);
        return $this->success(SupportTicketPresenter::base($ticket->fresh(['transaction', 'assignee'])), 'Ticket priority updated.');
    }

    public function assignment(Request $request, SupportTicket $ticket): JsonResponse
    {
        $data = $request->validate(['assigned_to' => 'nullable|integer|exists:users,id']);
        if (!empty($data['assigned_to'])) {
            $eligible = User::whereKey($data['assigned_to'])->whereHas('role', fn ($role) => $role->where('is_staff', true)->where('is_active', true)->whereHas('permissions', fn ($permission) => $permission->where('slug', 'support')))->exists();
            if (!$eligible) return $this->fail(['assigned_to' => ['The selected user is not eligible for support assignment.']], 'Validation failed.', 422);
        }
        $ticket->update(['assigned_to' => $data['assigned_to'] ?? null]);
        return $this->success(SupportTicketPresenter::base($ticket->fresh(['transaction', 'assignee'])), 'Ticket assignment updated.');
    }

    private function paginated($paginator, callable $map): JsonResponse
    {
        return response()->json(['message' => 'successful', 'success' => true, 'data' => collect($paginator->items())->map($map)->values(), 'meta' => ['current_page' => $paginator->currentPage(), 'last_page' => $paginator->lastPage(), 'per_page' => $paginator->perPage(), 'total' => $paginator->total()], 'type' => 'success']);
    }
}
