import PublicMarketingResources from '@/Components/Marketing/PublicMarketingResources';
import React from 'react';
import PageTitle from '@/Components/PageTitle';
import Header from '@/Components/Header';
import Footer from '@/Components/Footer';
import Hero from '@/Components/Marketing/Hero';
import { FeatureSteps } from '@/Components/Marketing/FeatureGrid';
import CtaBand from '@/Components/Marketing/CtaBand';
import { EyeIcon, MagnifyingGlassIcon, RocketLaunchIcon } from '@heroicons/react/24/outline';

/*
 * Shares its layout with RealEstateHowItWorks — the two were independent
 * implementations of the same three alternating step rows, which is why the
 * emoji illustrations survived here long after the other skin had line icons.
 */
const steps = [
    {
        icon: EyeIcon,
        title: '1. We read your website',
        body: 'Enter your website address and we take it from there. We read your site and pick up your colours, fonts, and the way you talk about your business — so your ads sound like you from day one.',
        bullets: [
            'Your colours and visual style, read automatically',
            'Your fonts and brand feel',
            'Your tone of voice and messaging',
            'Your products and services',
        ],
        panel: {
            title: 'Your brand, understood instantly',
            detail: "Just your website address. That's all we need.",
        },
    },
    {
        icon: MagnifyingGlassIcon,
        title: '2. We find your competitors',
        body: 'We look at who else is advertising in your space, read through their websites, and work out the best angles for your ads to stand out. Updated every week without you having to ask.',
        bullets: [
            'Finds competitors you might not know about',
            'Reads their websites and messaging',
            'Checks their pricing and offers',
            'Tells you exactly how to stand out from them',
        ],
        panel: {
            title: 'Know your competition',
            detail: 'Find their offers and identify your difference',
        },
    },
    {
        icon: RocketLaunchIcon,
        title: '3. Review, launch and monitor',
        body: "Review your campaign and budget before launch. On a managed plan, agents monitor delivery and performance, make supported changes and record why they acted.",
        bullets: [
            'Ad policy issues checked and supported repairs attempted',
            'Budget changes informed by available performance data',
            'New headline variations tested when there is useful evidence',
            'Audience work depends on the platform and available customer data',
        ],
        panel: {
            title: 'Improving every day',
            detail: 'Launch, learn, keep getting better',
        },
    },
];



/*
 * No <Head> here. Title, description, Open Graph pair and JSON-LD are built by
 * LandingController and printed server-side by app.blade.php.
 *
 * They used to be written here too. That did not override the server's copy —
 * Inertia only replaces tags it owns — it appended a second, differently
 * worded one: two descriptions, two og:titles, and structured data that existed
 * only after hydration and so was invisible to every AI crawler robots.txt
 * admits. See App\Http\Controllers\Concerns\RendersPageMeta.
 */
export default function HowItWorks({ auth, publicContent }) {
    return (
        <>
            <PageTitle />
            <div className="min-h-screen bg-white">
                <Header auth={auth} />

                <main>
                    <Hero
                        eyebrow={publicContent.eyebrow}
                        headline={publicContent.headline}
                        sub={publicContent.intro}
                    />

                    <FeatureSteps
                        items={steps}
                        cta={{ href: '/register', label: 'Start free' }}
                        note="No credit card required"
                    />

                    <CtaBand
                        title="Ready to get started?"
                        body="Create your account, review your business details and choose your campaign goal."
                        primaryCta={{ href: '/register', label: 'Start free' }}
                        secondaryCta={{ href: '/pricing', label: 'View pricing' }}
                        note="No credit card required to explore · Review before launch"
                    />
                <PublicMarketingResources content={publicContent} />
                </main>

                <Footer />
            </div>
        </>
    );
}
