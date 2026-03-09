<?php

namespace App\Http\Controllers;

use App\Models\AdminNotification;
use Illuminate\Http\Request;

class NotificationUserController extends Controller
{
    /**
     * List notifications for the authenticated user (individual + broadcasts).
     */
    public function index(Request $request)
    {
        $notifications = AdminNotification::forUser($request->user()->id)
            ->latest()
            ->paginate($request->input('per_page', 20));

        return response()->json($notifications);
    }

    /**
     * Mark a notification as read.
     */
    public function markRead(Request $request, AdminNotification $notification)
    {
        // User can only mark their own or broadcast notifications
        if (!$notification->is_broadcast && $notification->user_id !== $request->user()->id) {
            return response()->json(['message' => 'Not found.'], 404);
        }

        $notification->update(['is_read' => true]);

        return response()->json(['message' => 'Marked as read.']);
    }

    /**
     * Unread count.
     */
    public function unreadCount(Request $request)
    {
        $count = AdminNotification::forUser($request->user()->id)
            ->where('is_read', false)
            ->count();

        return response()->json(['count' => $count]);
    }
}
