import React, { useEffect } from 'react';
import { Head, Link } from '@inertiajs/react';
import Header from '@/Components/Header';
import Footer from '@/Components/Footer';
import { trackConversion } from '@/utils/conversions';

export default function Pricing({ auth, plans = [] }) {
    const [openFAQ, setOpenFAQ] = React.useState(null);
    useEffect(() => { trackConversion('pricing_visit'); }, []);

    const faqs = [
        { question: "What's included in the free tier?", answer: "The free tier lets you properly kick the tyres before you commit. You get 3 website or file sources for brand matching, 4 AI-generated images per campaign (with a watermark), 3 landing page audits, and unlimited ad copy. Going live on Google or Facebook requires a paid plan—upgrade whenever you're ready." },
        { question: "Does the subscription price include my ad budget?", answer: "No, and that's intentional. Your subscription pays for the platform and the AI doing the work. Your actual ad spend goes straight to Google, Facebook, and the other networks—we never touch it or mark it up. You always see exactly where every dollar goes." },
        { question: "How does ad spend billing work?", answer: "When you launch your first campaign, we load up 7 days' worth of estimated spend as a credit balance. Each morning at 6 AM we deduct the previous day's actual spend. When your balance starts running low, we top it up automatically so your campaigns never go dark unexpectedly." },
        { question: "What happens if a payment fails?", answer: "We give you 24 hours to sort it out—no immediate panic. If it's still unresolved after that, we trim budgets by 50% to slow spend. After another day we'll pause everything to make sure nobody's out of pocket. The moment your payment goes through, everything picks straight back up at full speed." },
        { question: "How do I update my payment method?", answer: "Head to Billing → Ad Spend in your dashboard. If a payment has failed you'll also see a 'Retry Payment' button—tap that after updating your card and we'll charge it right away and get your campaigns back up." },
        { question: "What do the AI specialists actually do?", answer: "Six of them, running 24/7. One finds your competitors. One digs into their websites to find angles you can use. One fixes any rejected ads before you even notice. One moves your budget to the hours your customers are most active. One tests different ad variations and keeps the winners. One finds new people who look like your existing customers. Together they do the work of a full marketing team." },
        { question: "How does competitor analysis work?", answer: "Every week we read your website to understand your business, then look at who else is advertising in your space. We check out their messaging, their offers, and what they're saying—then figure out the best way for you to stand out. You don't have to ask, it just happens." },
        { question: "What happens if my ad gets disapproved?", answer: "We catch it automatically, rewrite it so it passes Google's checks, and resubmit it—all without you needing to do anything. Ads that simply stop performing get paused before they waste more of your budget." },
        { question: 'How does it know what my brand looks like?', answer: "We take a screenshot of your website and our AI reads it—your colours, your fonts, your tone. Every ad we create matches your look without you needing to fill in a single form or upload a brand guide." },
        { question: "Can I switch plans later?", answer: "Absolutely. Upgrade or downgrade anytime from your dashboard. If you ever hit the limits of your plan we'll let you know before anything stops working." },
    ];

    return (
        <>
            <Head>
                <title>Pricing - Simple, Transparent Plans | sitetospend</title>
                <meta name="description" content="sitetospend pricing: Starter at $99/mo, Growth at $249/mo, Agency custom pricing. All plans include AI agents, campaign automation, and a 7-day free trial. No credit card required." />
                <meta property="og:title" content="Pricing — AI Ad Management from $99/mo | sitetospend" />
                <meta property="og:description" content="Starter $99/mo, Growth $249/mo, Agency custom. AI agents, campaign automation, and a free trial. No credit card required." />
                <meta name="twitter:title" content="Pricing — AI Ad Management from $99/mo | sitetospend" />
                <meta name="twitter:description" content="Starter $99/mo, Growth $249/mo, Agency custom. AI agents, campaign automation, and a free trial." />
                <script type="application/ld+json">{JSON.stringify({
                    "@context": "https://schema.org",
                    "@type": "FAQPage",
                    "mainEntity": faqs.map(faq => ({
                        "@type": "Question",
                        "name": faq.question,
                        "acceptedAnswer": {
                            "@type": "Answer",
                            "text": faq.answer
                        }
                    }))
                })}</script>
            </Head>
            <div className="min-h-screen bg-gray-50 text-gray-800">
                <Header auth={auth} />

                <main>
                    {/* Hero */}
                    <div className="bg-gradient-to-b from-flame-orange-50 to-white py-16 sm:py-24">
                        <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 text-center">
                            <p className="text-sm font-semibold text-flame-orange-600 uppercase tracking-wider">Pricing</p>
                            <h1 className="mt-3 text-4xl sm:text-5xl font-extrabold tracking-tight text-gray-900">
                                Simple, Transparent Pricing
                            </h1>
                            <p className="mt-6 max-w-2xl mx-auto text-lg text-gray-500">
                                Try free with generous limits. Upgrade when you're ready to deploy live campaigns.
                            </p>
                        </div>
                    </div>

                    {/* Comparison Table */}
                    <div className="bg-white py-16 sm:py-24">
                        <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                            <div className="text-center mb-12">
                                <h2 className="text-3xl font-bold text-gray-900">Why Choose AI Over a Traditional Agency?</h2>
                            </div>
                            <div className="max-w-4xl mx-auto overflow-hidden rounded-lg border border-gray-200 shadow-sm">
                                <table className="min-w-full divide-y divide-gray-200">
                                    <thead className="bg-gray-50">
                                        <tr>
                                            <th scope="col" className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider"></th>
                                            <th scope="col" className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Traditional Agency</th>
                                            <th scope="col" className="px-6 py-3 text-left text-xs font-bold text-flame-orange-600 uppercase tracking-wider">sitetospend AI</th>
                                        </tr>
                                    </thead>
                                    <tbody className="bg-white divide-y divide-gray-200">
                                        {[
                                            ['Cost', '$2,500 - $5,000 / month', 'From $99 / month'],
                                            ['Setup Time', '2-4 Weeks', '< 5 Minutes'],
                                            ['Brand Guidelines', 'Manual PDF creation (billed extra)', 'Instant Vision AI Extraction'],
                                            ['Creatives', 'Limited revisions', 'Unlimited AI Generation'],
                                            ['Optimization', 'Weekly manual checks', '24/7 Real-time Agent adjustments'],
                                            ['Competitor Analysis', 'Manual research (extra hours)', 'Automatic AI Discovery & Counter-Strategy'],
                                            ['Campaign Fixes', 'Wait for account manager response', 'Self-Optimising AI (instant fixes)'],
                                        ].map(([label, agency, ai]) => (
                                            <tr key={label}>
                                                <td className="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">{label}</td>
                                                <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-500">{agency}</td>
                                                <td className="px-6 py-4 whitespace-nowrap text-sm font-bold text-flame-orange-600">{ai}</td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                    {/* Pricing Cards */}
                    <div className="bg-gradient-to-b from-gray-50 to-white py-16 sm:py-24">
                        <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
                            <div className={`grid grid-cols-1 ${plans.length >= 3 ? 'md:grid-cols-3' : 'md:grid-cols-2'} gap-8 max-w-7xl mx-auto`}>
                                {plans.map((plan) => (
                                    <div
                                        key={plan.id}
                                        className={`rounded-lg p-8 flex flex-col relative ${
                                            plan.is_popular
                                                ? 'border-2 border-flame-orange-600 bg-flame-orange-50 shadow-lg'
                                                : 'border border-gray-200 bg-white'
                                        }`}
                                    >
                                        {plan.badge_text && (
                                            <div className="absolute -top-4 left-1/2 transform -translate-x-1/2 bg-flame-orange-600 text-white px-3 py-1 text-xs font-semibold rounded-full">
                                                {plan.badge_text}
                                            </div>
                                        )}
                                        <h3 className="text-2xl font-bold text-gray-900">{plan.name}</h3>
                                        <p className="mt-2 text-sm text-gray-500">{plan.description}</p>
                                        <div className="mt-4 text-gray-900">
                                            {plan.price_cents > 0 ? (
                                                <>
                                                    <span className="text-4xl font-extrabold">${Math.round(plan.price_cents / 100)}</span>
                                                    <span className="text-xl font-medium">/{plan.billing_interval === 'year' ? 'year' : 'mo'}</span>
                                                </>
                                            ) : !plan.is_free ? (
                                                <span className="text-4xl font-extrabold">Custom</span>
                                            ) : (
                                                <>
                                                    <span className="text-4xl font-extrabold">$0</span>
                                                    <span className="text-xl font-medium">/mo</span>
                                                </>
                                            )}
                                        </div>
                                        <ul className="mt-8 space-y-4 flex-grow">
                                            {(plan.features || []).map((item) => (
                                                <li key={item} className="flex items-start">
                                                    <svg className="h-6 w-6 text-green-500 mr-3 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M5 13l4 4L19 7" /></svg>
                                                    <p className="text-gray-700">{item}</p>
                                                </li>
                                            ))}
                                        </ul>
                                        <div className="mt-10">
                                            <a
                                                href={plan.cta_text === 'Contact Sales' ? 'mailto:hello@sitetospend.com?subject=Agency Plan Inquiry' : '/register'}
                                                className={`block w-full text-center rounded-lg px-6 py-3 text-base font-medium ${
                                                    plan.is_popular
                                                        ? 'bg-flame-orange-600 text-white hover:bg-flame-orange-700 shadow-lg'
                                                        : 'border-2 border-gray-300 text-gray-900 hover:border-gray-400'
                                                }`}
                                            >
                                                {plan.cta_text || 'Get Started'}
                                            </a>
                                        </div>
                                    </div>
                                ))}
                            </div>

                            {/* Trust Seals */}
                            <div className="mt-12 flex justify-center gap-8 text-sm text-gray-500">
                                <div className="flex items-center gap-2"><span>🔒</span> Secure Stripe Payment</div>
                                <div className="flex items-center gap-2"><span>⚡</span> Instant Campaign Deployment</div>
                                <div className="flex items-center gap-2"><span>🛡️</span> Data Encrypted & Private</div>
                            </div>
                        </div>
                    </div>

                    {/* FAQ */}
                    <div className="bg-white py-16 sm:py-24">
                        <div className="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8">
                            <div className="text-center mb-12">
                                <h2 className="text-3xl font-extrabold text-gray-900 sm:text-4xl">Frequently Asked Questions</h2>
                                <p className="mt-4 text-lg text-gray-500">Get answers to common questions about how sitetospend works.</p>
                            </div>

                            <div className="space-y-4">
                                {faqs.map((faq, index) => (
                                    <div key={index} className="border border-gray-200 rounded-lg">
                                        <button
                                            onClick={() => setOpenFAQ(openFAQ === index ? null : index)}
                                            className="w-full px-6 py-4 text-left flex items-center justify-between hover:bg-gray-50 transition-colors"
                                        >
                                            <h3 className="text-lg font-bold text-gray-900">{faq.question}</h3>
                                            <svg className={`h-6 w-6 text-flame-orange-600 flex-shrink-0 transition-transform ${openFAQ === index ? 'rotate-180' : ''}`} fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M19 14l-7 7m0 0l-7-7m7 7V3" />
                                            </svg>
                                        </button>
                                        {openFAQ === index && (
                                            <div className="px-6 py-4 border-t border-gray-200 bg-gray-50">
                                                <p className="text-gray-600">{faq.answer}</p>
                                            </div>
                                        )}
                                    </div>
                                ))}
                            </div>
                        </div>
                    </div>

                    {/* CTA */}
                    <div className="bg-gradient-to-r from-flame-orange-600 to-flame-orange-800 py-16 sm:py-24">
                        <div className="max-w-4xl mx-auto text-center px-4 sm:px-6 lg:px-8">
                            <h2 className="text-4xl font-extrabold text-white sm:text-5xl">
                                Ready to transform your marketing?
                            </h2>
                            <p className="mt-6 text-xl text-flame-orange-100">
                                Start creating smarter, faster campaigns with AI-powered optimization.
                            </p>
                            <div className="mt-10 flex flex-col sm:flex-row gap-4 justify-center">
                                <a href="/register" className="inline-flex items-center justify-center px-8 py-4 border border-transparent text-lg font-medium rounded-lg text-flame-orange-600 bg-white hover:bg-gray-50 shadow-lg">
                                    Get Started Free
                                </a>
                                <Link href="/features" className="inline-flex items-center justify-center px-8 py-4 border-2 border-white text-lg font-medium rounded-lg text-white hover:bg-flame-orange-700">
                                    Explore Features
                                </Link>
                            </div>
                            <p className="mt-8 text-flame-orange-100">✓ Free to explore · ✓ No credit card required · ✓ Live in minutes</p>
                        </div>
                    </div>
                </main>

                <Footer />
            </div>
        </>
    );
}
