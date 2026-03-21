<?php

namespace App\Http\Controllers;

use App\Models\Strategy;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;

class StrategyController extends Controller
{
    use AuthorizesRequests;

    /**
     * approve marks a strategy as approved.
     *
     * @param Strategy $strategy The strategy model instance (route-model binding).
     * @return \Illuminate\Http\RedirectResponse
     */
    public function approve(Strategy $strategy)
    {
        $this->authorize('update', $strategy);

        $strategy->update(['status' => 'approved']);

        return back()->with('success', 'Strategy approved!');
    }

    /**
     * update modifies the content of a strategy.
     *
     * @param Request $request The incoming HTTP request.
     * @param Strategy $strategy The strategy model instance.
     * @return \Illuminate\Http\RedirectResponse
     */
    public function update(Request $request, Strategy $strategy)
    {
        $this->authorize('update', $strategy);

        $validated = $request->validate([
            'ad_copy_strategy' => 'required|string',
            'imagery_strategy' => 'required|string',
            'video_strategy' => 'required|string',
        ]);

        $strategy->update($validated);

        return back()->with('success', 'Strategy updated!');
    }
}
