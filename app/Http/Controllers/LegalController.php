<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\RendersPageMeta;

class LegalController extends Controller
{
    use RendersPageMeta;

    /**
     * Show the terms of service page.
     *
     * These pages rendered <Head title="Terms of Service" />, which app.jsx
     * expands to "Terms of Service - Site to Spend" — a spelling of the brand
     * that appears nowhere else on the site, on the one page a visitor reads
     * when they are deciding whether to trust it.
     *
     * @return \Inertia\Response
     */
    public function terms()
    {
        return \Inertia\Inertia::render('Legal/Terms', [
            'legalContent' => file_get_contents(resource_path('legal/terms.html')),
            'publicContent' => ['type' => 'legal'],
            'meta' => $this->meta(
                'Terms of Service | sitetospend',
                'The terms covering your use of sitetospend: subscriptions, ad spend billing, account access, cancellation and liability.',
            ),
        ]);
    }

    /**
     * Show the privacy policy page.
     *
     * @return \Inertia\Response
     */
    public function privacy()
    {
        return \Inertia\Inertia::render('Legal/Privacy', [
            'legalContent' => file_get_contents(resource_path('legal/privacy.html')),
            'publicContent' => ['type' => 'legal'],
            'meta' => $this->meta(
                'Privacy Policy | sitetospend',
                'What data sitetospend collects, why we collect it, who we share it with, how long we keep it, and how to have it deleted.',
            ),
        ]);
    }
}
