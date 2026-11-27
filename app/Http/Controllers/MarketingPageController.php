<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\RendersPageMeta;
use App\Models\Plan;
use App\Support\MarketingPages;
use App\Support\PublicSeo;
use App\Support\Tenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class MarketingPageController extends Controller
{
    use RendersPageMeta;

    public function googleAdsManagement(): Response
    {
        return $this->show('google-ads-management');
    }

    public function aiAdsManagement(): Response
    {
        return $this->show('ai-ads-management');
    }

    public function googleAdsSetup(Request $request): Response|RedirectResponse
    {
        if (Tenant::singlePlan($request->attributes->get('tenant')['key'])) {
            return redirect()->route('google-ads-management', $request->query(), 301);
        }

        return $this->show('google-ads-setup');
    }

    private function show(string $slug): Response
    {
        $setupFee = number_format((int) config('services.stripe.setup_fee_usd_cents', 99900) / 100, 2, '.', ',');
        $setupFee = str_ends_with($setupFee, '.00') ? substr($setupFee, 0, -3) : $setupFee;
        $tenantKey = request()->attributes->get('tenant')['key'];
        $singlePlan = Tenant::singlePlan($tenantKey);
        $plans = Plan::offeredTo($tenantKey)->ordered()->where('is_free', false)->get();
        $startingPlan = null;
        $price = null;
        if ($slug === 'google-ads-management') {
            $startingPlan = $plans->filter(fn (Plan $plan) => $plan->slug !== 'setup_only'
                && $plan->billing_interval === 'month'
                && $plan->price_cents > 0
                && in_array('google', Plan::platformsFor($plan->slug), true))
                ->sortBy('price_cents')->first();
            $price = $startingPlan
                ? 'US$'.number_format($startingPlan->price_cents / 100, $startingPlan->price_cents % 100 === 0 ? 0 : 2, '.', ',').'/month'
                : null;
        }
        $service = MarketingPages::find($slug, $setupFee, $singlePlan, $price);
        abort_if($service === null, 404);

        $hero = null;
        if ($slug === 'google-ads-management') {
            $hero = [
                'headline' => ! $singlePlan && $price ? 'Google Ads management from '.$price : $service['headline'],
                'intro' => $singlePlan ? $service['intro'] : 'Site to Spend researches keywords, prepares Google Ads copy and monitors your campaign on a managed plan. Review your business information and ads before launch.',
                'primaryCta' => ['href' => '/#build-my-ads', 'label' => 'Build my ads'],
                'secondaryCta' => ['href' => '/pricing', 'label' => $singlePlan ? 'See pricing' : 'Compare plans'],
                'note' => 'Advertising spend is additional. Subscription prices are in US dollars.',
            ];
        }

        return Inertia::render('MarketingService', [
            'service' => $service,
            'publicContent' => ['type' => 'service', 'hero' => $hero],
            'plans' => $plans,
            'meta' => $this->meta($service['title'], $service['description'], schema: [[
                '@type' => 'Service',
                'name' => $service['headline'],
                'description' => $service['description'],
                'url' => PublicSeo::canonicalUrl(request()),
                'provider' => [
                    '@type' => 'Organization',
                    'name' => $singlePlan ? 'Site to Spend' : 'sitetospend',
                    'url' => PublicSeo::sharedUrl('/'),
                ],
                ...($singlePlan && $startingPlan ? ['offers' => $this->monthlyOfferSchema($startingPlan)] : []),
            ], $this->faqSchema($service['faqs'])]),
        ]);
    }
}
