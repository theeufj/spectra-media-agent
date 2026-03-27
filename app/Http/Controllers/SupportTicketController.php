<?php

namespace App\Http\Controllers;

use App\Mail\SupportTicketCreated;
use App\Models\SupportTicket;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Inertia\Inertia;

class SupportTicketController extends Controller
{
    /**
     * List tickets for the authenticated user.
     */
    public function index(Request $request)
    {
        $tickets = SupportTicket::where('user_id', $request->user()->id)
            ->orderByDesc('created_at')
            ->get();

        return Inertia::render('SupportTickets/Index', [
            'tickets' => $tickets,
        ]);
    }

    /**
     * Show ticket creation form.
     */
    public function create()
    {
        return Inertia::render('SupportTickets/Create');
    }

    /**
     * Store a new support ticket and email admin.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'subject' => 'required|string|max:255',
            'description' => 'required|string|max:5000',
            'priority' => 'required|in:low,normal,high,urgent',
            'category' => 'required|in:billing,technical,campaign,general',
        ]);

        $ticket = SupportTicket::create([
            'user_id' => $request->user()->id,
            'customer_id' => session('active_customer_id'),
            'subject' => $validated['subject'],
            'description' => $validated['description'],
            'priority' => $validated['priority'],
            'category' => $validated['category'],
        ]);

        $ticket->load(['user', 'customer']);

        Mail::to('theeufj@gmail.com')->send(new SupportTicketCreated($ticket));

        return redirect()->route('support-tickets.index')
            ->with('message', 'Support ticket submitted successfully. We\'ll get back to you soon!')
            ->with('type', 'success');
    }

    /**
     * Show a single ticket for the user.
     */
    public function show(Request $request, SupportTicket $supportTicket)
    {
        if ($supportTicket->user_id !== $request->user()->id) {
            abort(403);
        }

        $supportTicket->load(['user', 'customer', 'assignee']);

        return Inertia::render('SupportTickets/Show', [
            'ticket' => $supportTicket,
        ]);
    }
}
