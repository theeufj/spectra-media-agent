<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SupportTicket;
use Illuminate\Http\Request;
use Inertia\Inertia;

class SupportTicketController extends Controller
{
    /**
     * List all tickets for admin.
     */
    public function index(Request $request)
    {
        $query = SupportTicket::with(['user', 'customer', 'assignee'])
            ->orderByRaw("CASE WHEN status IN ('open','in_progress') THEN 0 ELSE 1 END")
            ->orderByRaw("CASE priority WHEN 'urgent' THEN 0 WHEN 'high' THEN 1 WHEN 'normal' THEN 2 WHEN 'low' THEN 3 END")
            ->orderByDesc('created_at');

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('subject', 'ilike', "%{$search}%")
                  ->orWhere('description', 'ilike', "%{$search}%")
                  ->orWhere('id', '=', is_numeric($search) ? (int) $search : 0)
                  ->orWhereHas('user', function ($uq) use ($search) {
                      $uq->where('name', 'ilike', "%{$search}%")
                         ->orWhere('email', 'ilike', "%{$search}%");
                  });
            });
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('priority')) {
            $query->where('priority', $request->priority);
        }

        if ($request->filled('category')) {
            $query->where('category', $request->category);
        }

        $tickets = $query->paginate(25)->withQueryString();

        $stats = [
            'open' => SupportTicket::where('status', 'open')->count(),
            'in_progress' => SupportTicket::where('status', 'in_progress')->count(),
            'resolved' => SupportTicket::where('status', 'resolved')->count(),
            'closed' => SupportTicket::where('status', 'closed')->count(),
        ];

        return Inertia::render('Admin/SupportTickets/Index', [
            'tickets' => $tickets,
            'stats' => $stats,
            'filters' => $request->only(['status', 'priority', 'search', 'category']),
        ]);
    }

    /**
     * Show a single ticket with full details.
     */
    public function show(SupportTicket $supportTicket)
    {
        $supportTicket->load(['user', 'customer', 'assignee']);

        return Inertia::render('Admin/SupportTickets/Show', [
            'ticket' => $supportTicket,
        ]);
    }

    /**
     * Update ticket status, assignment, or admin response.
     */
    public function update(Request $request, SupportTicket $supportTicket)
    {
        $validated = $request->validate([
            'status' => 'sometimes|in:open,in_progress,resolved,closed',
            'priority' => 'sometimes|in:low,normal,high,urgent',
            'admin_response' => 'sometimes|nullable|string|max:5000',
            'assigned_to' => 'sometimes|nullable|exists:users,id',
        ]);

        if (isset($validated['status']) && in_array($validated['status'], ['resolved', 'closed']) && !$supportTicket->resolved_at) {
            $validated['resolved_at'] = now();
        }

        $supportTicket->update($validated);

        return back()->with('message', 'Ticket updated successfully.')->with('type', 'success');
    }
}
