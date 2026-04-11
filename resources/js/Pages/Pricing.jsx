import React from 'react';
import { Head, Link } from '@inertiajs/react';
import Header from '@/Components/Header';
import Footer from '@/Components/Footer';

export default function Pricing({ auth, plans = [] }) {
    const [openFAQ, setOpenFAQ] = React.useState(null);

    const faqs = [
        { question: "What's included in the free tier?", answer: "Our free tier lets you explore the platform before committing. You get: 3 brand sources (URLs or files) for brand extraction, 4 AI-generated images per campaign (with watermark), 3 landing page CRO audits, and unlimited ad copy generation. Deployment to Google/Facebook requires a subscription. Upgrade anytime to unlock unlimited generation, watermark-free images, and live campaign deployment." },
        { question: "Does the subscription price include my ad budget?", answer: "No. Your subscription covers the Site to Spend AI platform, the creative generation, and the autonomous management agents. You will connect your own credit card to Google/Facebook, so you pay the ad networks directly for your media spend. This ensures total transparency—we never mark up your ad costs." },
        { question: "How does ad spend billing work?", answer: "When you launch your first campaign, we charge 7 days of estimated ad spend upfront as credit. This ensures your campaigns have guaranteed funding from day one. We then bill your actual daily spend each morning at 6 AM, deducting from your credit balance. When your balance gets low (less than 3 days remaining), we automatically top it up to keep your campaigns running smoothly." },
        { question: "What happens if a payment fails?", answer: "We give you a 24-hour grace period to update your payment method. If the payment still fails after Day 1, we reduce campaign budgets by 50% to minimize spend. If payment hasn't been resolved by Day 2, we pause all campaigns to protect both parties. Once payment is successful, campaigns are automatically resumed at full budget—no action needed from you." },
        { question: "How do I update my payment method?", answer: "You can update your payment method anytime in your dashboard under Billing → Ad Spend. If a payment has failed, you'll see a 'Retry Payment' button that will immediately attempt to charge your new card and resume your campaigns if successful." },
        { question: "What do the AI agents actually do?", answer: "Our 6 autonomous agents work 24/7: The Competitor Discovery Agent finds your competitors via Google Search, the Analysis Agent scrapes their websites for messaging insights, the Self-Healing Agent fixes disapproved ads automatically, the Budget Intelligence Agent optimizes spend by time-of-day, the Creative Intelligence Agent A/B tests your ads and generates new variations, and the Audience Intelligence Agent manages your customer lists." },
        { question: "How does competitor analysis work?", answer: "Every week, our AI reads your website content to understand your business, then uses Google Search to discover real competitors in your space. It scrapes their websites, extracts their messaging, value propositions, and pricing, then generates counter-strategies with specific ad copy recommendations to help you win." },
        { question: "What happens if my ad gets disapproved?", answer: "Our Self-Healing Agent automatically detects disapproved ads and rewrites the copy to be policy-compliant while maintaining your brand voice. It then resubmits the ad—all without any action needed from you. Underperforming ads are also automatically paused to protect your budget." },
        { question: 'How does the "Vision AI" know my brand?', answer: "We use Gemini Pro Vision to analyze screenshots of your website. It instantly extracts your hex codes, fonts, tone of voice, and visual style to ensure every ad we generate looks exactly like it came from your internal design team." },
        { question: "Can I switch plans later?", answer: "Absolutely. You can upgrade or downgrade at any time from your dashboard. If you exceed the ad spend limit of your tier, we'll simply notify you to upgrade to keep your campaigns running at peak performance." },
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
                                            ['Campaign Fixes', 'Wait for account manager response', 'Self-Healing AI (instant fixes)'],
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
                                <div className="flex items-center gap-2"><span>⚡</span> Approved Google Partner</div>
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
                                Join hundreds of marketing teams creating smarter, faster campaigns with AI-powered optimization.
                            </p>
                            <div className="mt-10 flex flex-col sm:flex-row gap-4 justify-center">
                                <a href="/register" className="inline-flex items-center justify-center px-8 py-4 border border-transparent text-lg font-medium rounded-lg text-flame-orange-600 bg-white hover:bg-gray-50 shadow-lg">
                                    Get Started Free
                                </a>
                                <Link href="/features" className="inline-flex items-center justify-center px-8 py-4 border-2 border-white text-lg font-medium rounded-lg text-white hover:bg-flame-orange-700">
                                    Explore Features
                                </Link>
                            </div>
                            <p className="mt-8 text-flame-orange-100">✓ Generous free tier · ✓ No credit card required · ✓ Deploy in minutes</p>
                        </div>
                    </div>
                </main>

                <Footer />
            </div>
        </>
    );
}
