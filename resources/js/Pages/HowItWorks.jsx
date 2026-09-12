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
            detail: 'Find them, read them, beat them',
        },
    },
    {
        icon: RocketLaunchIcon,
        title: '3. Your ads run themselves',
        body: "Launch with one click. From then on, rejected ads get fixed, budget moves to where it's performing, and we're always testing new ideas to improve your results. Every day, automatically.",
        bullets: [
            'Rejected ads fixed and resubmitted automatically',
            'Budget shifts to your best-performing hours every day',
            'Continuously testing headline variations to find winners',
            'Refreshes lookalike audiences from your customers every week',
        ],
        panel: {
            title: 'Improving every day',
            detail: 'Launch, learn, keep getting better',
        },
    },
];

const testimonials = [
    { quote: "sitetospend completely transformed how we run ads for our security platform. The AI agents handle our Google Ads around the clock—competitor targeting, budget shifts, creative testing—all automated. We've cut our ad management time by 80%.", name: 'Josh T.', role: 'Founder', company: 'Proveably', url: 'https://proveably.com' },
    { quote: "As an event platform, we need to reach the right audience fast. sitetospend found competitors we didn't even know about and built campaigns that outperformed our old agency from day one. The self-optimising ads alone saved us thousands.", name: 'Jamie L.', role: 'Founder', company: 'PapSnap', url: 'https://papsnap.com' },
    { quote: "Running an e-commerce store builder means I don't have time to babysit ad campaigns. sitetospend's AI agents do it all—budget optimization, creative testing, audience targeting. The results have been incredible for a fraction of what we were paying our agency.", name: 'Mike R.', role: 'Co-Founder', company: 'YourFirstStore', url: 'https://yourfirststore.com' },
    { quote: "Managing marketing for a golf marketplace with venues, coaches, and members is complex. sitetospend's AI agents handle the nuance beautifully—different campaigns for different audiences, all optimized automatically. It's like having a full marketing team on autopilot.", name: 'Alicia M.', role: 'Founder', company: 'Zonely', url: 'https://zonely.co' },
    { quote: "We've been in digital marketing for 20+ years and sitetospend is genuinely impressive. We use it for clients who need always-on campaign optimization. The budget shifting and creative testing features deliver results that rival hands-on management—at a fraction of the effort.", name: 'Daniel K.', role: 'Director', company: 'First Digital', url: 'https://firstdigital.co.nz' },
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
export default function HowItWorks({ auth }) {
    return (
        <>
            <PageTitle />
            <div className="min-h-screen bg-white">
                <Header auth={auth} />

                <main>
                    <Hero
                        eyebrow="How it works"
                        headline="Up and running in 3 steps"
                        sub="Just enter your website address. We handle everything else."
                    />

                    <FeatureSteps
                        items={steps}
                        cta={{ href: '/register', label: 'Start free' }}
                        note="No credit card required"
                    />

                    {/* Testimonials */}
                    <div className="bg-gray-50 py-12 sm:py-24">
                        <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                            <div className="mx-auto mb-8 max-w-2xl sm:mb-16 lg:text-center">
                                <h2 className="text-3xl font-bold tracking-tight text-gray-900 sm:text-4xl">From Our Customers</h2>
                                <p className="mt-3 text-base text-gray-600 sm:mt-4 sm:text-lg">See how businesses like yours are getting on.</p>
                            </div>
                            <div className="grid max-w-2xl grid-cols-1 gap-4 sm:grid-cols-2 sm:gap-8 xl:max-w-none xl:grid-cols-3">
                                {testimonials.map((testimonial) => (
                                    <div key={testimonial.name} className="relative rounded-2xl bg-white p-5 shadow-sm ring-1 ring-gray-900/5 transition-shadow hover:shadow-md sm:p-6">
                                        <div className="mb-4 flex gap-1">
                                            {/*
                                                Gold stars cannot clear 3:1 on white at any usable
                                                shade, so the rating is carried by text for anyone
                                                who cannot see them rather than by colour alone.
                                            */}
                                            <span className="sr-only">Rated 5 out of 5</span>
                                            {[...Array(5)].map((_, i) => <span key={i} className="text-yellow-400" aria-hidden="true">★</span>)}
                                        </div>
                                        <p className="text-base font-medium text-gray-900 sm:text-lg">"{testimonial.quote}"</p>
                                        <div className="mt-4 font-semibold sm:mt-6">{testimonial.name}</div>
                                        <div className="text-sm text-gray-600">{testimonial.role}, <a href={testimonial.url} target="_blank" rel="noopener noreferrer" className="text-brand-dark hover:text-brand-darker">{testimonial.company}</a></div>
                                    </div>
                                ))}
                            </div>
                        </div>
                    </div>

                    <CtaBand
                        title="Ready to get started?"
                        body="Sign up free and see your first ads ready to launch in minutes."
                        primaryCta={{ href: '/register', label: 'Start free' }}
                        secondaryCta={{ href: '/pricing', label: 'View pricing' }}
                        note="No credit card required · Free to explore · Live in minutes"
                    />
                </main>

                <Footer />
            </div>
        </>
    );
}
