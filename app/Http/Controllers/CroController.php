<?php

namespace App\Http\Controllers;

use App\Jobs\RunCroAudit;
use App\Models\Customer;
use App\Models\LandingPageAudit;
use Illuminate\Http\Request;
use Inertia\Inertia;

class CroController extends Controller
{
    public function index(Request $request)
    {
        $customer = Customer::find(session('active_customer_id'));

        if (!$customer) {
            return redirect()->route('dashboard');
        }

        $user = $request->user();
        $plan = $user->resolveCurrentPlan();
        $slug = $plan?->slug ?? 'free';

        $isUnlimited = in_array($slug, ['growth', 'agency']);
        $maxAudits = $isUnlimited ? null : 3;
        $auditsUsed = $customer->cro_audits_used ?? 0;
        $canRunAudit = $isUnlimited || $auditsUsed < 3;

        $audits = LandingPageAudit::where('customer_id', $customer->id)
            ->orderByDesc('created_at')
            ->paginate(20);

        $domain = parse_url($customer->website_url ?? '', PHP_URL_HOST);

        return Inertia::render('SEO/CroIndex', [
            'audits' => $audits,
            'auditsUsed' => $auditsUsed,
            'maxAudits' => $maxAudits,
            'canRunAudit' => $canRunAudit,
            'domain' => $domain,
        ]);
    }

    public function show(LandingPageAudit $audit)
    {
        $customer = Customer::find(session('active_customer_id'));

        if (!$customer || $audit->customer_id !== $customer->id) {
            abort(403);
        }

        return Inertia::render('SEO/CroAudit', [
            'audit' => $audit,
        ]);
    }

    public function run(Request $request)
    {
        $request->validate([
            'url' => 'required|url|max:2048',
        ]);

        $customer = Customer::find(session('active_customer_id'));

        if (!$customer) {
            return redirect()->route('dashboard');
        }

        $user = $request->user();
        $plan = $user->resolveCurrentPlan();
        $slug = $plan?->slug ?? 'free';

        $isUnlimited = in_array($slug, ['growth', 'agency']);

        if (!$isUnlimited && ($customer->cro_audits_used ?? 0) >= 3) {
            return redirect()->back()->withErrors([
                'url' => 'You\'ve used all 3 free CRO audits. Upgrade to Growth for unlimited audits.',
            ]);
        }

        RunCroAudit::dispatch($customer->id, $request->input('url'));

        return redirect()->route('seo.cro')->with('success', 'CRO audit started! Results will appear below shortly.');
    }
}
