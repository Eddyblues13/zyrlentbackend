<?php

namespace App\Http\Controllers;

use App\Models\SupportTicket;
use Illuminate\Http\Request;

class SupportTicketUserController extends Controller
{
    /**
     * List tickets for the authenticated user.
     */
    public function index(Request $request)
    {
        $tickets = $request->user()->supportTickets()
            ->latest()
            ->paginate($request->input('per_page', 10));

        return response()->json($tickets);
    }

    /**
     * Create a new support ticket.
     */
    public function store(Request $request)
    {
        $request->validate([
            'subject'  => 'required|string|max:255',
            'message'  => 'required|string|max:5000',
            'priority' => 'nullable|in:low,medium,high,urgent',
        ]);

        $ticket = SupportTicket::create([
            'user_id'  => $request->user()->id,
            'subject'  => $request->subject,
            'message'  => $request->message,
            'priority' => $request->priority ?? 'medium',
        ]);

        return response()->json([
            'message' => 'Support ticket created successfully.',
            'ticket'  => $ticket,
        ], 201);
    }

    /**
     * Show a single ticket (user must own it).
     */
    public function show(Request $request, SupportTicket $ticket)
    {
        if ($ticket->user_id !== $request->user()->id) {
            return response()->json(['message' => 'Not found.'], 404);
        }

        return response()->json($ticket);
    }
}
