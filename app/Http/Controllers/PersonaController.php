<?php

namespace App\Http\Controllers;

use App\Models\Persona;
use App\Services\PersonaGeneratorService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class PersonaController extends Controller
{
    public function index(Request $request)
    {
        $customer = $this->getActiveCustomer($request);
        if (!$customer) return redirect()->route('dashboard');

        $personas = Persona::where('customer_id', $customer->id)
            ->orderByDesc('is_active')
            ->orderByDesc('created_at')
            ->get();

        $campaigns = $customer->campaigns()->select('id', 'name')->orderByDesc('created_at')->get();

        return Inertia::render('Personas/Index', [
            'personas' => $personas,
            'campaigns' => $campaigns,
        ]);
    }

    public function generate(Request $request)
    {
        $customer = $this->getActiveCustomer($request);
        if (!$customer) return redirect()->route('dashboard');

        $validated = $request->validate([
            'campaign_id' => 'nullable|exists:campaigns,id',
            'count' => 'integer|min:1|max:6',
        ]);

        $campaign = isset($validated['campaign_id'])
            ? $customer->campaigns()->findOrFail($validated['campaign_id'])
            : null;

        $service = app(PersonaGeneratorService::class);
        $personas = $service->generate($customer, $campaign, $validated['count'] ?? 4);

        if (empty($personas)) {
            return back()->with('error', 'Failed to generate personas. Please try again.');
        }

        return back()->with('success', count($personas) . ' personas generated.');
    }

    public function store(Request $request)
    {
        $customer = $this->getActiveCustomer($request);
        if (!$customer) return redirect()->route('dashboard');

        $validated = $request->validate([
            'campaign_id' => 'nullable|exists:campaigns,id',
            'name' => 'required|string|max:100',
            'description' => 'required|string|max:500',
            'demographics' => 'nullable|array',
            'psychographics' => 'nullable|array',
            'pain_points' => 'nullable|array',
            'messaging_angle' => 'nullable|string|max:500',
            'tone_adjustments' => 'nullable|array',
        ]);

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
        if (!$customer || $persona->customer_id !== $customer->id) {
            return redirect()->route('dashboard');
        }

        $validated = $request->validate([
            'name' => 'string|max:100',
            'description' => 'string|max:500',
            'messaging_angle' => 'nullable|string|max:500',
            'tone_adjustments' => 'nullable|array',
            'is_active' => 'boolean',
        ]);

        $persona->update($validated);
        return back()->with('success', 'Persona updated.');
    }

    public function destroy(Request $request, Persona $persona)
    {
        $customer = $this->getActiveCustomer($request);
        if (!$customer || $persona->customer_id !== $customer->id) {
            return redirect()->route('dashboard');
        }

        $persona->delete();
        return back()->with('success', 'Persona deleted.');
    }
}
