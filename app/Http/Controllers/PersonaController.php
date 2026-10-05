<?php

namespace App\Http\Controllers;

use App\Models\Persona;
use App\Services\PersonaGeneratorService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class PersonaController extends Controller
{
    public function index(Request $request)
    {
        $customer = $this->getActiveCustomer($request);
        if (! $customer) {
            return redirect()->route('dashboard');
        }

        $personas = Persona::where('customer_id', $customer->id)->with('campaign:id,name')->withCount('adCopies')
            ->orderByDesc('is_active')
            ->orderByDesc('created_at')->orderByDesc('id')
            ->get();

        $campaigns = $customer->campaigns()->select('id', 'name')->orderByDesc('created_at')->orderByDesc('id')->get();

        return Inertia::render('Personas/Index', [
            'personas' => $personas,
            'campaigns' => $campaigns,
        ]);
    }

    public function generate(Request $request)
    {
        $customer = $this->getActiveCustomer($request);
        if (! $customer) {
            return redirect()->route('dashboard');
        }

        $validated = $request->validate([
            'campaign_id' => 'nullable|integer',
            'count' => 'integer|min:1|max:6',
        ]);

        $campaign = isset($validated['campaign_id'])
            ? $customer->campaigns()->findOrFail($validated['campaign_id'])
            : null;

        $this->authorize('create', Persona::class);
        $service = app(PersonaGeneratorService::class);
        $personas = $service->generate($customer, $campaign, $validated['count'] ?? 1);

        if (empty($personas)) {
            return back()->with('error', 'Failed to generate personas. Please try again.');
        }

        return back()->with('success', count($personas).' personas generated.');
    }

    public function store(Request $request)
    {
        $customer = $this->getActiveCustomer($request);
        if (! $customer) {
            return redirect()->route('dashboard');
        }

        $validated = $request->validate([
            'campaign_id' => 'nullable|integer',
            'name' => 'required|string|max:100',
            'description' => 'required|string|max:500',
            'demographics' => 'nullable|array',
            'psychographics' => 'nullable|array',
            'pain_points' => 'nullable|array',
            'messaging_angle' => 'nullable|string|max:500',
            'tone_adjustments' => 'nullable|array',
        ]);

        $this->authorize('create', Persona::class);
        if (! empty($validated['campaign_id'])) {
            $customer->campaigns()->findOrFail($validated['campaign_id']);
        }
        Persona::create([
            'customer_id' => $customer->id,
            ...$validated,
            'source' => 'manual',
        ]);

        return back()->with('success', 'Persona created.');
    }

    public function update(Request $request, Persona $persona)
    {
        $customer = $this->getActiveCustomer($request);
        if (! $customer || $persona->customer_id !== $customer->id) {
            return redirect()->route('dashboard');
        }

        $this->authorize('update', $persona);
        $validated = $request->validate([
            'campaign_id' => 'nullable|integer',
            'use_for_generation' => 'nullable|boolean',
            'pain_points' => 'nullable|array',
            'pain_points.*' => 'string|max:500',
            'name' => 'string|max:100',
            'description' => 'string|max:500',
            'messaging_angle' => 'nullable|string|max:500',
            'tone_adjustments' => 'nullable|array',
            'is_active' => 'boolean',
        ]);

        if (! empty($validated['campaign_id'])) {
            $customer->campaigns()->findOrFail($validated['campaign_id']);
        }
        $useForGeneration = $validated['use_for_generation'] ?? false;
        unset($validated['use_for_generation']);
        DB::transaction(function () use ($customer, $persona, $validated, $useForGeneration) {
            $customer->newQuery()->whereKey($customer->id)->lockForUpdate()->firstOrFail();
            $persona->update($validated);
            if ($useForGeneration) {
                Persona::where('customer_id', $customer->id)->where('campaign_id', $persona->campaign_id)->whereKeyNot($persona->id)->update(['is_active' => false]);
                $persona->update(['is_active' => true]);
            }
        });

        return back()->with('success', 'Persona updated.');
    }

    public function destroy(Request $request, Persona $persona)
    {
        $customer = $this->getActiveCustomer($request);
        if (! $customer || $persona->customer_id !== $customer->id) {
            return redirect()->route('dashboard');
        }

        $this->authorize('delete', $persona);
        $persona->delete();

        return back()->with('success', 'Persona deleted.');
    }
}
