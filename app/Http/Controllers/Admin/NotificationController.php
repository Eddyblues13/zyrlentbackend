<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminNotification;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;

class NotificationController extends Controller
{
    public function index(Request $request)
    {
        $query = AdminNotification::with('user:id,name,email');

        if ($request->has('broadcast')) {
            $query->where('is_broadcast', $request->boolean('broadcast'));
        }

        $notifications = $query->latest()->paginate($request->input('per_page', 15));
        return response()->json($notifications);
    }

    public function broadcast(Request $request)
    {
        $data = $request->validate([
            'title'      => 'required|string|max:255',
            'message'    => 'required|string|max:2000',
            'type'       => 'nullable|in:info,warning,promo,system',
            'link_url'   => 'nullable|string|max:500',
            'link_label' => 'nullable|string|max:100',
        ]);

        $notification = AdminNotification::create([
            'title'        => $data['title'],
            'message'      => $data['message'],
            'type'         => $data['type'] ?? 'info',
            'link_url'     => !empty($data['link_url']) ? $data['link_url'] : null,
            'link_label'   => !empty($data['link_label']) ? $data['link_label'] : null,
            'is_broadcast' => true,
            'is_active'    => true,
            'user_id'      => null,
        ]);

        return response()->json([
            'message'      => 'Broadcast notification sent to all users.',
            'notification' => $notification,
        ]);
    }

    public function update(Request $request, AdminNotification $notification)
    {
        $data = $request->validate([
            'title'      => 'sometimes|required|string|max:255',
            'message'    => 'sometimes|required|string|max:2000',
            'type'       => 'sometimes|in:info,warning,promo,system',
            'link_url'   => 'nullable|string|max:500',
            'link_label' => 'nullable|string|max:100',
            'is_active'  => 'sometimes|boolean',
        ]);

        // Normalize empties to null
        if (array_key_exists('link_url', $data) && empty($data['link_url'])) {
            $data['link_url'] = null;
        }
        if (array_key_exists('link_label', $data) && empty($data['link_label'])) {
            $data['link_label'] = null;
        }

        $notification->update($data);

        return response()->json([
            'message'      => 'Notification updated.',
            'notification' => $notification->fresh(),
        ]);
    }

    public function destroy(AdminNotification $notification)
    {
        $notification->delete();

        return response()->json([
            'message' => 'Notification deleted.',
        ]);
    }

    public function toggleActive(AdminNotification $notification)
    {
        $notification->update(['is_active' => !$notification->is_active]);

        return response()->json([
            'message'      => $notification->is_active ? 'Notification activated.' : 'Notification deactivated.',
            'notification' => $notification,
        ]);
    }

    public function emailBlast(Request $request)
    {
        $request->validate([
            'subject' => 'required|string|max:255',
            'body'    => 'required|string|max:5000',
        ]);

        $users = User::where('is_suspended', false)->pluck('email');

        $sent = 0;
        foreach ($users as $email) {
            try {
                Mail::raw($request->body, function ($mail) use ($email, $request) {
                    $mail->to($email)->subject($request->subject);
                });
                $sent++;
            } catch (\Exception $e) {
                \Log::warning("Email blast failed for {$email}: " . $e->getMessage());
            }
        }

        return response()->json([
            'message' => "Email sent to {$sent} out of {$users->count()} users.",
        ]);
    }
}
