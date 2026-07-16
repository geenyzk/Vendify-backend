<?php

namespace App\Http\Controllers;

use App\HttpResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use App\Models\User;

class NotificationController extends Controller
{
    use HttpResponse;

    /**
     * The authenticated user's own notifications (admin or customer — both
     * are just Users), newest first. Uses Laravel's built-in database
     * notifications (Notifiable trait + the `notifications` table already
     * shipped with this app) rather than a custom table.
     */
    public function index(Request $request): JsonResponse
    {
        $perPage = min(max((int) $request->integer('per_page', 10), 1), 50);
        $notifications = Auth::user()
            ->notifications()
            ->latest()
            ->paginate($perPage);

        return response()->json([
            'message' => 'successful',
            'success' => true,
            'data' => $notifications->items(),
            'meta' => [
                'current_page' => $notifications->currentPage(),
                'last_page' => $notifications->lastPage(),
                'per_page' => $notifications->perPage(),
                'total' => $notifications->total(),
            ],
            'type' => 'success',
        ]);
    }

    public function unreadCount(): JsonResponse
    {
        $count = DB::table('notifications')
            ->where('notifiable_type', User::class)
            ->where('notifiable_id', Auth::id())
            ->whereNull('read_at')
            ->count();

        return $this->success(['count' => $count]);
    }

    public function markRead(string $id): JsonResponse
    {
        $notification = Auth::user()->notifications()->where('id', $id)->firstOrFail();
        $notification->markAsRead();

        return $this->success(null, 'Marked as read');
    }

    public function markAllRead(): JsonResponse
    {
        Auth::user()->unreadNotifications->markAsRead();

        return $this->success(null, 'All notifications marked as read');
    }
}
