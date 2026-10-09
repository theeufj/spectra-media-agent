<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\RendersPageMeta;
use App\Models\Plan;
use App\Support\MarketingPages;
use App\Support\PublicSeo;
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

    public function googleAdsSetup(): Response
    {
        return $this->show('google-ads-setup');
    }

    private function show(string $slug): Response
    {
        $setupFee = number_format((int) config('services.stripe.setup_fee_usd_cents') / 100, 2, '.', ',');
        $setupFee = str_ends_with($setupFee, '.00') ? substr($setupFee, 0, -3) : $setupFee;
        $service = MarketingPages::find($slug, $setupFee);
        abort_if($service === null, 404);

        return Inertia::render('MarketingService', [
            'service' => $service,
            'publicContent' => ['type' => 'service'],
            'plans' => Plan::active()->ordered()->where('is_free', false)->get(),
            'meta' => $this->meta($service['title'], $service['description'], schema: [[
                '@type' => 'Service',
                'name' => $service['headline'],
                'description' => $service['description'],
                'url' => PublicSeo::sharedUrl('/'.$slug),
                'provider' => [
                    '@type' => 'Organization',
                    'name' => 'sitetospend',
                    'url' => PublicSeo::sharedUrl('/'),
                ],
            ], $this->faqSchema($service['faqs'])]),
        ]);
    }
}
