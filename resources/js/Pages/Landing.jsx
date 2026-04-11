import React from 'react';
import { Head, Link } from '@inertiajs/react';
import Header from '@/Components/Header';
import Footer from '@/Components/Footer';

export default function Landing({ auth, plans = [] }) {
    const paidPlans = plans.filter(p => p.price_cents > 0 && !p.is_free);
    const lowestPrice = paidPlans.length > 0 ? Math.round(Math.min(...paidPlans.map(p => p.price_cents)) / 100) : 99;
    return (
        <>
            <Head>
                <title>AI-Powered Ad Campaign Management | sitetospend</title>
                <meta name="description" content={`Agency-level digital advertising powered by AI. 6 autonomous agents optimize your Google Ads & Facebook Ads 24/7—competitor discovery, self-healing campaigns, budget intelligence, and more. From $${lowestPrice}/mo.`} />
                <meta property="og:title" content="sitetospend — AI-Powered Ad Campaign Management" />
                <meta property="og:description" content={`6 autonomous AI agents optimize your ad campaigns 24/7. Agency-level results from $${lowestPrice}/mo. No credit card required.`} />
                <meta property="og:type" content="website" />
                <meta name="twitter:title" content="sitetospend — AI-Powered Ad Campaign Management" />
                <meta name="twitter:description" content={`6 autonomous AI agents optimize your ad campaigns 24/7. Agency-level results from $${lowestPrice}/mo.`} />
                <script type="application/ld+json">{JSON.stringify({
                    "@context": "https://schema.org",
                    "@graph": [
                        {
                            "@type": "Organization",
                            "name": "sitetospend",
                            "url": "https://sitetospend.com",
                            "logo": "https://sitetospend.com/og-image.png",
                            "description": "AI-powered digital advertising platform with autonomous agents that manage and optimize ad campaigns across Google, Facebook, Microsoft, and LinkedIn.",
                            "sameAs": []
                        },
                        {
                            "@type": "SoftwareApplication",
                            "name": "sitetospend",
                            "applicationCategory": "BusinessApplication",
                            "operatingSystem": "Web",
                            "url": "https://sitetospend.com",
                            "description": "Autonomous AI agents that create, manage, and optimize digital ad campaigns across Google Ads, Facebook Ads, Microsoft Ads, and LinkedIn Ads.",
                            "offers": {
                                "@type": "AggregateOffer",
                                "lowPrice": lowestPrice,
                                "highPrice": "249",
                                "priceCurrency": "USD",
                                "offerCount": paidPlans.length
                            },
                            "featureList": [
                                "AI Competitor Discovery",
                                "Self-Healing Campaigns",
                                "Budget Intelligence",
                                "Creative A/B Testing",
                                "Audience Intelligence",
                                "Vision AI Brand Extraction"
                            ]
                        },
                        {
                            "@type": "FAQPage",
                            "mainEntity": [
                                {
                                    "@type": "Question",
                                    "name": "How does sitetospend work?",
                                    "acceptedAnswer": {
                                        "@type": "Answer",
                                        "text": "Enter your website URL. Our Vision AI extracts your brand identity, competitive intelligence discovers your rivals, and 6 autonomous agents optimize your campaigns 24/7."
                                    }
                                },
                                {
                                    "@type": "Question",
                                    "name": "Which ad platforms does sitetospend support?",
                                    "acceptedAnswer": {
                                        "@type": "Answer",
                                        "text": "sitetospend manages campaigns across Google Ads, Facebook Ads, Microsoft Ads (Bing), and LinkedIn Ads from a single dashboard."
                                    }
                                },
                                {
                                    "@type": "Question",
                                    "name": "Do I need a credit card to start?",
                                    "acceptedAnswer": {
                                        "@type": "Answer",
                                        "text": "No. sitetospend offers a generous free tier with no credit card required. You can upgrade to a paid plan at any time."
                                    }
                                }
                            ]
                        }
                    ]
                })}</script>
            </Head>
            <div className="min-h-screen bg-gray-50 text-gray-800">
                <Header auth={auth} />

                <main>
                    {/* Hero Section */}
                    <div className="pt-6 px-4 sm:pt-10 md:pt-14 lg:pt-8 lg:pb-14 lg:overflow-hidden bg-gradient-to-b from-flame-orange-50 to-white">
                        <div className="mx-auto max-w-7xl lg:px-8">
                            <div className="grid grid-cols-1 md:grid-cols-2 gap-6 md:gap-8 lg:gap-8">
                                <div className="mx-auto max-w-sm px-2 sm:max-w-md sm:px-4 md:max-w-none md:px-0 text-center sm:text-center md:text-left md:flex md:items-center">
                                    <div className="py-8 sm:py-12 md:py-16 lg:py-24 w-full">
                                        <p className="text-xs sm:text-sm font-semibold text-flame-orange-600 uppercase tracking-wider">AI-Powered Ad Campaign Management</p>
                                        <h1 className="mt-3 sm:mt-4 text-3xl sm:text-4xl md:text-5xl lg:text-5xl xl:text-6xl tracking-tight font-extrabold text-gray-900">
                                            <span className="block whitespace-normal">The results of a top-tier agency.</span>
                                            <span className="block text-flame-orange-600 whitespace-normal">The cost of a utility bill.</span>
                                        </h1>
                                        <p className="mt-2 sm:mt-3 text-sm sm:text-base md:text-lg lg:text-lg xl:text-xl text-gray-500 leading-relaxed">
                                            Stop paying thousands in retainer fees. Our AI agents discover your competitors, heal broken campaigns automatically, optimize budgets in real-time, and A/B test creatives 24/7—all while you sleep.
                                        </p>
                                        <div className="mt-6 sm:mt-8 md:mt-10 lg:mt-10 flex flex-col xs:flex-col sm:flex-row gap-3 sm:gap-4">
                                            <a href="/register" className="inline-flex items-center justify-center px-4 sm:px-6 py-2 sm:py-3 border border-transparent text-sm sm:text-base font-medium rounded-md text-white bg-flame-orange-600 hover:bg-flame-orange-700 shadow-lg transition-colors w-full sm:w-auto">
                                                Start Generating for Free
                                            </a>
                                            <a href="/free-audit" className="inline-flex items-center justify-center px-4 sm:px-6 py-2 sm:py-3 border-2 border-flame-orange-600 text-sm sm:text-base font-medium rounded-md text-flame-orange-600 bg-white hover:bg-flame-orange-50 transition-colors w-full sm:w-auto">
                                                Free Ad Account Audit
                                            </a>
                                        </div>
                                        <p className="mt-4 sm:mt-6 text-xs sm:text-sm text-gray-500">✓ No credit card required · ✓ Generous free tier · ✓ Cancel anytime</p>
                                    </div>
                                </div>
                                
                            </div>
                        </div>
                    </div>

                    {/* Social Proof */}
                    <div className="bg-white py-12 border-b border-gray-200">
                        <div className="mx-auto max-w-7xl px-6 lg:px-8">
                            <p className="text-center text-sm font-semibold text-gray-500 uppercase tracking-wider">Trusted by leading brands</p>
                            <div className="mt-8 flex justify-center items-center gap-x-10 gap-y-4 flex-wrap">
                                <a href="https://proveably.com" target="_blank" rel="noopener noreferrer" className="text-gray-400 font-semibold hover:text-gray-600 transition-colors">Proveably</a>
                                <a href="https://papsnap.com" target="_blank" rel="noopener noreferrer" className="text-gray-400 font-semibold hover:text-gray-600 transition-colors">PapSnap</a>
                                <a href="https://yourfirststore.com" target="_blank" rel="noopener noreferrer" className="text-gray-400 font-semibold hover:text-gray-600 transition-colors">YourFirstStore</a>
                                <a href="https://zonely.co" target="_blank" rel="noopener noreferrer" className="text-gray-400 font-semibold hover:text-gray-600 transition-colors">Zonely</a>
                                <a href="https://firstdigital.co.nz" target="_blank" rel="noopener noreferrer" className="text-gray-400 font-semibold hover:text-gray-600 transition-colors">First Digital</a>
                            </div>
                        </div>
                    </div>

                    {/* How It Works - Teaser */}
                    <div className="bg-white py-16 sm:py-24">
                        <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                            <div className="mx-auto max-w-2xl lg:text-center mb-14">
                                <h2 className="text-3xl sm:text-4xl font-bold tracking-tight text-gray-900">From URL to ROI in 3 Steps</h2>
                                <p className="mt-4 text-lg leading-8 text-gray-600">
                                    Our autonomous agents handle the complex workflow so you don't have to.
                                </p>
                            </div>
                            <div className="grid grid-cols-1 md:grid-cols-3 gap-12 relative">
                                <div className="hidden md:block absolute top-12 left-[16%] right-[16%] h-0.5 bg-flame-orange-100 -z-10"></div>
                                {[
                                    { icon: '👁️', title: '1. Vision AI Extraction', desc: 'Enter your URL. Gemini Vision AI instantly extracts your hex codes, fonts, and brand voice.' },
                                    { icon: '🧠', title: '2. Competitive Intelligence', desc: 'AI discovers your real competitors, scrapes their sites, and generates counter-strategies.' },
                                    { icon: '🚀', title: '3. Autonomous Optimization', desc: 'Self-Healing Agents fix ads, Budget Intelligence shifts spend, Creative Testing finds winners.' },
                                ].map((step) => (
                                    <div key={step.title} className="relative flex flex-col items-center text-center">
                                        <div className="flex items-center justify-center h-24 w-24 rounded-full bg-flame-orange-50 border-4 border-white shadow-lg mb-6">
                                            <span className="text-4xl">{step.icon}</span>
                                        </div>
                                        <h3 className="text-xl font-bold text-gray-900 mb-3">{step.title}</h3>
                                        <p className="text-gray-600 leading-relaxed">{step.desc}</p>
                                    </div>
                                ))}
                            </div>
                            <div className="mt-12 text-center">
                                <Link href="/how-it-works" className="text-flame-orange-600 font-semibold hover:text-flame-orange-700 transition-colors">
                                    Learn more about how it works →
                                </Link>
                            </div>
                        </div>
                    </div>

                    {/* Agents Highlight */}
                    <div className="bg-gradient-to-br from-flame-orange-900 via-flame-orange-800 to-purple-900 py-16 sm:py-24">
                        <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 text-center">
                            <p className="text-flame-orange-300 font-semibold text-sm uppercase tracking-wider">Autonomous AI Agents</p>
                            <h2 className="mt-2 text-3xl sm:text-4xl font-bold tracking-tight text-white">Your 24/7 Marketing Team</h2>
                            <p className="mt-4 max-w-2xl mx-auto text-lg text-flame-orange-200">
                                Six specialized AI agents work around the clock to optimize every aspect of your campaigns.
                            </p>
                            <div className="mt-12 grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-6">
                                {[
                                    { icon: '🔍', name: 'Competitor Discovery' },
                                    { icon: '📊', name: 'Competitor Analysis' },
                                    { icon: '🩹', name: 'Self-Healing' },
                                    { icon: '💰', name: 'Budget Intelligence' },
                                    { icon: '🎨', name: 'Creative Intelligence' },
                                    { icon: '👥', name: 'Audience Intelligence' },
                                ].map((agent) => (
                                    <div key={agent.name} className="bg-white/10 backdrop-blur-lg rounded-xl p-5 border border-white/20">
                                        <span className="text-3xl">{agent.icon}</span>
                                        <p className="mt-3 text-sm font-semibold text-white">{agent.name}</p>
                                    </div>
                                ))}
                            </div>
                            <div className="mt-12 flex flex-col sm:flex-row gap-4 justify-center">
                                <a href="/register" className="inline-flex items-center justify-center px-8 py-4 border border-transparent text-lg font-medium rounded-lg text-flame-orange-900 bg-white hover:bg-flame-orange-50 shadow-lg transition-colors">
                                    Put These Agents to Work →
                                </a>
                                <Link href="/features" className="inline-flex items-center justify-center px-8 py-4 border-2 border-white text-lg font-medium rounded-lg text-white hover:bg-white/10 transition-colors">
                                    See All Features
                                </Link>
                            </div>
                        </div>
                    </div>

                    {/* Pricing Teaser */}
                    <div className="bg-gradient-to-b from-gray-50 to-white py-16 sm:py-24">
                        <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
                            <div className="text-center mb-16">
                                <h2 className="text-3xl font-extrabold text-gray-900 sm:text-4xl">Simple, Transparent Pricing</h2>
                                <p className="mt-4 text-xl text-gray-500">Agency-level results starting at just ${lowestPrice}/month.</p>
                            </div>
                            <div className={`grid grid-cols-1 ${plans.length >= 3 ? 'md:grid-cols-3' : 'md:grid-cols-2'} gap-8 max-w-4xl mx-auto`}>
                                {plans.map((plan) => (
                                    <div key={plan.id} className={`rounded-lg p-8 text-center ${plan.is_popular ? 'border-2 border-flame-orange-600 bg-flame-orange-50 shadow-lg relative' : 'border border-gray-200 bg-white'}`}>
                                        {plan.badge_text && (
                                            <div className="absolute -top-4 left-1/2 transform -translate-x-1/2 bg-flame-orange-600 text-white px-3 py-1 text-xs font-semibold rounded-full">{plan.badge_text}</div>
                                        )}
                                        <h3 className="text-2xl font-bold text-gray-900">{plan.name}</h3>
                                        <p className="mt-2 text-sm text-gray-500">{plan.description}</p>
                                        <div className="mt-4">{plan.price_cents > 0 ? (<><span className="text-4xl font-extrabold text-gray-900">${Math.round(plan.price_cents / 100)}</span><span className="text-xl font-medium">/{plan.billing_interval === 'year' ? 'yr' : 'mo'}</span></>) : !plan.is_free ? (<span className="text-4xl font-extrabold text-gray-900">Custom</span>) : (<><span className="text-4xl font-extrabold text-gray-900">$0</span><span className="text-xl font-medium">/mo</span></>)}</div>
                                    </div>
                                ))}
                            </div>
                            <div className="mt-12 text-center">
                                <Link href="/pricing" className="inline-flex items-center justify-center px-8 py-4 border border-transparent text-lg font-medium rounded-lg text-white bg-flame-orange-600 hover:bg-flame-orange-700 shadow-lg transition-colors">
                                    Compare Plans & Start Free Trial
                                </Link>
                            </div>
                        </div>
                    </div>

                    {/* Final CTA */}
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
                                <a href="/login" className="inline-flex items-center justify-center px-8 py-4 border-2 border-white text-lg font-medium rounded-lg text-white hover:bg-flame-orange-700">
                                    Sign In
                                </a>
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
