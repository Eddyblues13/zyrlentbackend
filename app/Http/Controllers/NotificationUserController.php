<?php

namespace App\Http\Controllers;

use App\Models\AdminNotification;
use Illuminate\Http\Request;

class NotificationUserController extends Controller
{
    /**
     * List active notifications for the authenticated user.
     */
    public function index(Request $request)
    {
        $notifications = AdminNotification::forUser($request->user()->id)
            ->latest()
            ->paginate($request->input('per_page', 20));

        return response()->json($notifications);
    }

    /**
     * Count of active notifications.
     */
    public function count(Request $request)
    {
        $count = AdminNotification::forUser($request->user()->id)->count();

        return response()->json(['count' => $count]);
    }
}
