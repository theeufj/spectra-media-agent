<?php

namespace App\Http\Controllers;

use App\Models\CreativeBrief;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class CreativeBriefController extends Controller
{
    public function index(Request $request): Response
    {
        $user     = Auth::user();
        $customer = $user->customers()->findOrFail(session('active_customer_id'));

        $status = $request->get('status', 'pending');

        $briefs = CreativeBrief::where('customer_id', $customer->id)
            ->when($status !== 'all', fn($q) => $q->where('status', $status))
            ->with('campaign:id,name')
            ->orderByDesc('created_at')
            ->paginate(20)
            ->withQueryString();

        $counts = CreativeBrief::where('customer_id', $customer->id)
            ->selectRaw("status, COUNT(*) as total")
            ->groupBy('status')
            ->pluck('total', 'status');

        return Inertia::render('CreativeBriefs/Index', [
            'briefs'       => $briefs,
            'counts'       => $counts,
            'activeStatus' => $status,
        ]);
    }

    public function action(CreativeBrief $creativeBrief): RedirectResponse
    {
        $this->authorizeBrief($creativeBrief);
        $creativeBrief->markActioned();

        return back()->with('success', 'Brief marked as actioned.');
    }

    public function dismiss(CreativeBrief $creativeBrief): RedirectResponse
    {
        $this->authorizeBrief($creativeBrief);
        $creativeBrief->dismiss();

        return back()->with('success', 'Brief dismissed.');
    }

    private function authorizeBrief(CreativeBrief $brief): void
    {
        $user     = Auth::user();
        $customer = $user->customers()->findOrFail(session('active_customer_id'));

        abort_if($brief->customer_id !== $customer->id, 403);
    }
}
