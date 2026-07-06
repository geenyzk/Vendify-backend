<?php

namespace App\Http\Controllers;

use App\HttpResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

class NotificationController extends Controller
{
    use HttpResponse;

    /**
     * The authenticated user's own notifications (admin or customer — both
     * are just Users), newest first. Uses Laravel's built-in database
     * notifications (Notifiable trait + the `notifications` table already
     * shipped with this app) rather than a custom table.
     */
    public function index(): JsonResponse
    {
        $notifications = Auth::user()->notifications()->latest()->limit(50)->get();

        return $this->success($notifications);
    }

    public function unreadCount(): JsonResponse
    {
        return $this->success(['count' => Auth::user()->unreadNotifications()->count()]);
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
