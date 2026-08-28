<?php

namespace App\Http\Controllers;

use App\HttpResponse;
use App\Models\SupportTicket;
use App\Models\Transaction;
use App\Models\User;
use App\Notifications\SupportTicketNotification;
use App\Services\SupportTicketPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\Rule;

class SupportTicketController extends Controller
{
    use HttpResponse;

    public function index(Request $request): JsonResponse
    {
        $request->validate(['status' => ['nullable', Rule::in(SupportTicket::STATUSES)], 'per_page' => 'nullable|integer|min:1|max:50']);
        $query = SupportTicket::where('user_id', $request->user()->id)->with(['transaction', 'assignee'])->latest('updated_at');
        if ($request->filled('status')) $query->where('status', $request->string('status'));
        $tickets = $query->paginate($request->integer('per_page', 15));

        return $this->paginated($tickets, fn ($ticket) => SupportTicketPresenter::base($ticket));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'transaction_id' => 'nullable|integer',
            'category' => ['required', Rule::in(SupportTicket::CATEGORIES)],
            'issue_type' => ['nullable', Rule::in(SupportTicket::ISSUE_TYPES)],
            'subject' => 'required|string|min:3|max:160',
            'description' => 'required|string|min:10|max:10000',
        ]);

        $transaction = null;
        if (!empty($data['transaction_id'])) {
            $transaction = Transaction::whereKey($data['transaction_id'])->where('user_id', $request->user()->id)->first();
            if (!$transaction) return $this->fail(['transaction_id' => ['The selected transaction is invalid.']], 'Validation failed.', 422);
        }
        if ($data['category'] === 'transaction' && !$transaction) {
            return $this->fail(['transaction_id' => ['A transaction is required for transaction tickets.']], 'Validation failed.', 422);
        }

        $ticket = DB::transaction(function () use ($data, $request) {
            $ticket = SupportTicket::create($data + ['user_id' => $request->user()->id]);
            $ticket->messages()->create([
                'sender_id' => $request->user()->id,
                'sender_role' => 'customer',
                'message' => $data['description'],
            ]);
            return $ticket;
        });

        $request->user()->notify(new SupportTicketNotification($ticket, 'created'));
        $this->notifySupportStaff($ticket, 'created');
        return $this->success(SupportTicketPresenter::customerDetail($this->customerTicket($request, $ticket->id)), 'Support ticket created.', 201);
    }

    public function show(Request $request, int $ticket): JsonResponse
    {
        return $this->success(SupportTicketPresenter::customerDetail($this->customerTicket($request, $ticket)));
    }

    public function reply(Request $request, int $ticket): JsonResponse
    {
        $data = $request->validate(['message' => 'required|string|min:1|max:10000']);
        $ticketModel = $this->customerTicket($request, $ticket, false);
        if ($ticketModel->status === 'closed') return $this->fail([], 'Closed tickets cannot receive replies.', 409);

        $message = DB::transaction(function () use ($ticketModel, $request, $data) {
            $message = $ticketModel->messages()->create(['sender_id' => $request->user()->id, 'sender_role' => 'customer', 'message' => $data['message']]);
            if ($ticketModel->status === 'awaiting_customer') $ticketModel->update(['status' => 'in_review']);
            else $ticketModel->touch();
            return $message;
        });
        $this->notifySupportStaff($ticketModel->fresh(), 'customer_replied');
        return $this->success(SupportTicketPresenter::message($message->load('sender')), 'Reply added.', 201);
    }

    public function transactions(Request $request): JsonResponse
    {
        $request->validate(['per_page' => 'nullable|integer|min:1|max:50']);
        $transactions = Transaction::where('user_id', $request->user()->id)->latest()->paginate($request->integer('per_page', 20));
        return $this->paginated($transactions, fn ($transaction) => SupportTicketPresenter::transaction($transaction));
    }

    private function customerTicket(Request $request, int $id, bool $detail = true): SupportTicket
    {
        $query = SupportTicket::whereKey($id)->where('user_id', $request->user()->id);
        if ($detail) $query->with(['transaction', 'assignee', 'messages.sender']);
        return $query->firstOrFail();
    }

    private function paginated($paginator, callable $map): JsonResponse
    {
        return response()->json(['message' => 'successful', 'success' => true, 'data' => collect($paginator->items())->map($map)->values(), 'meta' => [
            'current_page' => $paginator->currentPage(), 'last_page' => $paginator->lastPage(),
            'per_page' => $paginator->perPage(), 'total' => $paginator->total(),
        ], 'type' => 'success']);
    }

    private function notifySupportStaff(SupportTicket $ticket, string $event): void
    {
        $staff = User::whereHas('role', fn ($role) => $role->where('is_staff', true)->where('is_active', true)
            ->whereHas('permissions', fn ($permission) => $permission->where('slug', 'support')))->get();
        Notification::send($staff, new SupportTicketNotification($ticket, $event));
    }
}
