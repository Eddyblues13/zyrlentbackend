<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminNotification;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;

class NotificationController extends Controller
{
    /**
     * List all sent notifications — paginated.
     */
    public function index(Request $request)
    {
        $query = AdminNotification::with('user:id,name,email');

        if ($request->has('broadcast')) {
            $query->where('is_broadcast', $request->boolean('broadcast'));
        }

        $notifications = $query->latest()->paginate($request->input('per_page', 15));

        return response()->json($notifications);
    }

    /**
     * Broadcast notification to all users.
     */
    public function broadcast(Request $request)
    {
        $request->validate([
            'title'   => 'required|string|max:255',
            'message' => 'required|string|max:2000',
            'type'    => 'nullable|in:info,warning,promo,system',
        ]);

        AdminNotification::create([
            'title'        => $request->title,
            'message'      => $request->message,
            'type'         => $request->type ?? 'info',
            'is_broadcast' => true,
            'user_id'      => null,
        ]);

        return response()->json([
            'message' => 'Broadcast notification sent to all users.',
        ]);
    }

    /**
     * Send email blast to all users.
     */
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
