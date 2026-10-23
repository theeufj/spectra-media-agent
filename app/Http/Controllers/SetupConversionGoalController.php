<?php

namespace App\Http\Controllers;

use App\Jobs\ConfigureSetupConversionGoal;
use App\Models\Campaign;
use App\Services\Onboarding\SetupConversionGoal;
use Illuminate\Http\Request;
use Inertia\Inertia;

class SetupConversionGoalController extends Controller
{
    public function edit(Request $request, Campaign $campaign, SetupConversionGoal $service)
    {
        $this->authorize('update', $campaign);

        return Inertia::render('Campaigns/ConversionGoal', $service->page($campaign, $request->query('strategy')));
    }

    public function update(Request $request, Campaign $campaign, SetupConversionGoal $service)
    {
        $this->authorize('update', $campaign);
        $goal = $request->validate([
            'objective' => 'required|in:lead,signup,purchase',
            'trigger' => 'required|in:thank_you_url,event,existing_action,prepare_only',
            'thank_you_url' => 'nullable|required_if:trigger,thank_you_url|url:https|max:2048',
            'event_name' => 'nullable|required_if:trigger,event|string|regex:/^[A-Za-z][A-Za-z0-9_]{1,99}$/',
            'action_resource' => 'nullable|required_if:trigger,existing_action|string|max:200',
            'strategy' => 'nullable|uuid',
        ]);
        $strategy = $goal['strategy'] ?? null;
        unset($goal['strategy']);
        $requestId = $service->request($campaign, $goal, $strategy);
        ConfigureSetupConversionGoal::dispatch($campaign, $requestId);

        return to_route('campaigns.conversion-goal.edit', ['campaign' => $campaign, 'strategy' => $strategy])->with('flash', [
            'type' => 'success', 'message' => 'Goal saved. We are configuring it while your campaign stays paused.',
        ]);
    }

    public function status(Request $request, Campaign $campaign, SetupConversionGoal $service)
    {
        $this->authorize('update', $campaign);

        return response()->json($service->status($campaign, $request->query('strategy')));
    }
}
