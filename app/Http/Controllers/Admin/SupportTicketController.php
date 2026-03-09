<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SupportTicket;
use Illuminate\Http\Request;

class SupportTicketController extends Controller
{
    /**
     * List all support tickets — paginated, filterable.
     */
    public function index(Request $request)
    {
        $query = SupportTicket::with('user:id,name,email');

        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('ticket_ref', 'like', "%{$search}%")
                  ->orWhere('subject', 'like', "%{$search}%")
                  ->orWhereHas('user', fn($q2) => $q2->where('name', 'like', "%{$search}%")
                      ->orWhere('email', 'like', "%{$search}%"));
            });
        }

        if ($status = $request->input('status')) {
            $query->where('status', $status);
        }

        if ($priority = $request->input('priority')) {
            $query->where('priority', $priority);
        }

        $tickets = $query->latest()->paginate($request->input('per_page', 15));

        return response()->json($tickets);
    }

    /**
     * Show a single ticket.
     */
    public function show(SupportTicket $ticket)
    {
        $ticket->load('user:id,name,email', 'admin:id,name');
        return response()->json($ticket);
    }

    /**
     * Reply to a ticket.
     */
    public function reply(Request $request, SupportTicket $ticket)
    {
        $request->validate([
            'reply'  => 'required|string|max:5000',
            'status' => 'nullable|in:open,in_progress,resolved,closed',
        ]);

        $ticket->update([
            'admin_reply' => $request->reply,
            'admin_id'    => $request->user()->id,
            'replied_at'  => now(),
            'status'      => $request->status ?? 'in_progress',
        ]);

        return response()->json([
            'message' => 'Reply sent successfully.',
            'ticket'  => $ticket->fresh()->load('user:id,name,email', 'admin:id,name'),
        ]);
    }

    /**
     * Update ticket status.
     */
    public function updateStatus(Request $request, SupportTicket $ticket)
    {
        $request->validate([
            'status' => 'required|in:open,in_progress,resolved,closed',
        ]);

        $ticket->update(['status' => $request->status]);

        return response()->json([
            'message' => "Ticket status updated to {$request->status}.",
        ]);
    }
}
