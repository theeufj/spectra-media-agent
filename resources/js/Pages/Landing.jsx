import React from 'react';
import { Head } from '@inertiajs/react';
import Header from '@/Components/Header';
import Footer from '@/Components/Footer';

export default function Landing({ auth }) {
    const [openFAQ, setOpenFAQ] = React.useState(null);
    return (
        <>
            <Head title="sitetospend - AI-Powered Digital Marketing" />
            <div className="min-h-screen bg-gray-50 text-gray-800">
                <Header auth={auth} />

                <main>
                    {/* Hero Section - Enhanced */}
                    <div className="pt-6 px-4 sm:pt-10 md:pt-14 lg:pt-8 lg:pb-14 lg:overflow-hidden bg-gradient-to-b from-indigo-50 to-white">
                        <div className="mx-auto max-w-7xl lg:px-8">
                            <div className="grid grid-cols-1 md:grid-cols-2 gap-6 md:gap-8 lg:gap-8">
                                <div className="mx-auto max-w-sm px-2 sm:max-w-md sm:px-4 md:max-w-none md:px-0 text-center sm:text-center md:text-left md:flex md:items-center">
                                    <div className="py-8 sm:py-12 md:py-16 lg:py-24 w-full">
                                        <p className="text-xs sm:text-sm font-semibold text-indigo-600 uppercase tracking-wider">AI That Understands Your Brand</p>
                                        <h1 className="mt-3 sm:mt-4 text-3xl sm:text-4xl md:text-5xl lg:text-5xl xl:text-6xl tracking-tight font-extrabold text-gray-900">
                                            <span className="block whitespace-normal">The results of a top-tier agency.</span>
                                            <span className="block text-indigo-600 whitespace-normal">The cost of a utility bill.</span>
                                        </h1>
                                        <p className="mt-2 sm:mt-3 text-sm sm:text-base md:text-lg lg:text-lg xl:text-xl text-gray-500 leading-relaxed">
                                            Stop paying thousands in retainer fees. Our AI agents discover your competitors, heal broken campaigns automatically, optimize budgets in real-time, and A/B test creatives 24/7—all while you sleep.
                                        </p>
                                        <div className="mt-6 sm:mt-8 md:mt-10 lg:mt-10 flex flex-col xs:flex-col sm:flex-row gap-3 sm:gap-4">
                                            <a
                                                href="/register"
                                                className="inline-flex items-center justify-center px-4 sm:px-6 py-2 sm:py-3 border border-transparent text-sm sm:text-base font-medium rounded-md text-white bg-indigo-600 hover:bg-indigo-700 shadow-lg transition-colors w-full sm:w-auto"
                                            >
                                                Start Generating for Free
                                            </a>
                                            <a
                                                href="#how-it-works"
                                                className="inline-flex items-center justify-center px-4 sm:px-6 py-2 sm:py-3 border-2 border-indigo-600 text-sm sm:text-base font-medium rounded-md text-indigo-600 bg-white hover:bg-indigo-50 transition-colors w-full sm:w-auto"
                                            >
                                                See How It Works
                                            </a>
                                        </div>
                                        <p className="mt-4 sm:mt-6 text-xs sm:text-sm text-gray-500">✓ No credit card required · ✓ Free forever tier · ✓ Cancel anytime</p>
                                    </div>
                                </div>
                                <div className="mt-12 -mb-16 sm:-mb-48 lg:m-0 lg:relative">
                                    <div className="mx-auto max-w-md px-4 sm:max-w-2xl sm:px-6 lg:max-w-none lg:px-0">
                                        {/* Replace with a relevant image or illustration */}
                                        <img className="w-full lg:absolute lg:inset-y-0 lg:left-0 lg:h-full lg:w-auto lg:max-w-none" src="https://tailwindui.com/img/component-images/cloud-illustration-indigo-400.svg" alt="" />
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    {/* Social Proof Section */}
                    <div className="bg-white py-12 border-b border-gray-200">
                        <div className="mx-auto max-w-7xl px-6 lg:px-8">
                            <p className="text-center text-sm font-semibold text-gray-500 uppercase tracking-wider">Trusted by leading brands</p>
                            <div className="mt-8 flex justify-center items-center gap-8 flex-wrap">
                                <div className="text-gray-400 font-semibold">TechStartup Co.</div>
                                <div className="text-gray-400 font-semibold">eCommerce Plus</div>
                                <div className="text-gray-400 font-semibold">SaaS Solutions</div>
                                <div className="text-gray-400 font-semibold">Local Services</div>
                            </div>
                        </div>
                    </div>

                    {/* Platforms Section */}
                    <div className="bg-gray-50 py-16 sm:py-24">
                        <div className="mx-auto max-w-7xl px-6 lg:px-8">
                            <div className="mx-auto max-w-2xl lg:text-center">
                                <h2 className="text-3xl font-bold tracking-tight text-gray-900 sm:text-4xl">One-Click Deployment to All Your Platforms</h2>
                                <p className="mt-6 text-lg leading-8 text-gray-600">
                                    Connect your accounts and let our AI agents handle the rest. We're constantly adding new platforms to our roster.
                                </p>
                            </div>
                            <div className="mt-16 flex justify-center">
                                <div className="flex flex-wrap justify-center gap-8">
                                    {/* Google Ads - Active */}
                                    <div className="flex flex-col items-center text-center">
                                        <div className="flex h-24 w-24 items-center justify-center rounded-full bg-green-100">
                                            {/* Placeholder for Google Ads Logo */}
                                            <svg className="h-12 w-12 text-green-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M13 10V3L4 14h7v7l9-11h-7z" /></svg>
                                        </div>
                                        <p className="mt-4 font-semibold text-gray-900">Google Ads</p>
                                        <p className="text-sm text-green-600 font-medium">Available Now</p>
                                    </div>
                                    {/* Meta/Facebook Ads - Coming Soon */}
                                    <div className="flex flex-col items-center text-center grayscale">
                                        <div className="relative flex h-24 w-24 items-center justify-center rounded-full bg-gray-100">
                                            <span className="absolute -top-1 -right-1 inline-flex items-center rounded-full bg-yellow-50 px-2 py-1 text-xs font-medium text-yellow-800 ring-1 ring-inset ring-yellow-600/20">Coming Soon</span>
                                            {/* Placeholder for Facebook Ads Logo */}
                                            <svg className="h-12 w-12 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 8c1.657 0 3-1.343 3-3s-1.343-3-3-3-3 1.343-3 3 1.343 3 3 3zm0 2c-2.21 0-4 1.79-4 4v1h8v-1c0-2.21-1.79-4-4-4z" /></svg>
                                        </div>
                                        <p className="mt-4 font-semibold text-gray-900">Meta Ads</p>
                                    </div>
                                    {/* Instagram Ads - Coming Soon */}
                                    <div className="flex flex-col items-center text-center grayscale">
                                        <div className="relative flex h-24 w-24 items-center justify-center rounded-full bg-gray-100">
                                            <span className="absolute -top-1 -right-1 inline-flex items-center rounded-full bg-yellow-50 px-2 py-1 text-xs font-medium text-yellow-800 ring-1 ring-inset ring-yellow-600/20">Coming Soon</span>
                                            {/* Placeholder for Instagram Ads Logo */}
                                            <svg className="h-12 w-12 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l-1.586-1.586a2 2 0 00-2.828 0L6 14m6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z" /></svg>
                                        </div>
                                        <p className="mt-4 font-semibold text-gray-900">Instagram Ads</p>
                                    </div>
                                    {/* Reddit Ads - Coming Soon */}
                                    <div className="flex flex-col items-center text-center grayscale">
                                        <div className="relative flex h-24 w-24 items-center justify-center rounded-full bg-gray-100">
                                            <span className="absolute -top-1 -right-1 inline-flex items-center rounded-full bg-yellow-50 px-2 py-1 text-xs font-medium text-yellow-800 ring-1 ring-inset ring-yellow-600/20">Coming Soon</span>
                                            {/* Placeholder for Reddit Ads Logo */}
                                            <svg className="h-12 w-12 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 15a3 3 0 100-6 3 3 0 000 6z" /><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                                        </div>
                                        <p className="mt-4 font-semibold text-gray-900">Reddit Ads</p>
                                    </div>
                                    {/* Microsoft Ads - Coming Soon */}
                                    <div className="flex flex-col items-center text-center grayscale">
                                        <div className="relative flex h-24 w-24 items-center justify-center rounded-full bg-gray-100">
                                            <span className="absolute -top-1 -right-1 inline-flex items-center rounded-full bg-yellow-50 px-2 py-1 text-xs font-medium text-yellow-800 ring-1 ring-inset ring-yellow-600/20">Coming Soon</span>
                                            <svg className="h-12 w-12 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                                        </div>
                                        <p className="mt-4 font-semibold text-gray-900">Microsoft Ads</p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    {/* How It Works Section */}
                    <div id="how-it-works" className="bg-white py-12 sm:py-16 md:py-20 lg:py-24">
                        <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                            <div className="mx-auto max-w-2xl lg:text-center mb-12 sm:mb-14 md:mb-16">
                                <h2 className="text-2xl sm:text-3xl md:text-4xl font-bold tracking-tight text-gray-900">From URL to ROI in 3 Steps</h2>
                                <p className="mt-3 sm:mt-4 text-base sm:text-lg leading-8 text-gray-600">
                                    Our autonomous agents handle the complex backend workflow so you don't have to.
                                </p>
                            </div>
                            <div className="grid grid-cols-1 md:grid-cols-3 gap-12 relative">
                                {/* Connecting Line (Desktop only) */}
                                <div className="hidden md:block absolute top-12 left-[16%] right-[16%] h-0.5 bg-indigo-100 -z-10"></div>

                                {/* Step 1 */}
                                <div className="relative flex flex-col items-center text-center">
                                    <div className="flex items-center justify-center h-24 w-24 rounded-full bg-indigo-50 border-4 border-white shadow-lg mb-6">
                                        <span className="text-4xl">👁️</span>
                                    </div>
                                    <h3 className="text-xl font-bold text-gray-900 mb-3">1. Vision AI Extraction</h3>
                                    <p className="text-gray-600 leading-relaxed">
                                        Enter your URL. Our <strong>Crawler</strong> takes a high-res screenshot, and <strong>Gemini Vision AI</strong> instantly extracts your hex codes, fonts, and brand voice. No manual setup required.
                                    </p>
                                </div>

                                {/* Step 2 */}
                                <div className="relative flex flex-col items-center text-center">
                                    <div className="flex items-center justify-center h-24 w-24 rounded-full bg-indigo-50 border-4 border-white shadow-lg mb-6">
                                        <span className="text-4xl">🧠</span>
                                    </div>
                                    <h3 className="text-xl font-bold text-gray-900 mb-3">2. Competitive Intelligence</h3>
                                    <p className="text-gray-600 leading-relaxed">
                                        Our <strong>Competitor Discovery Agent</strong> uses Google Search to find your real competitors. The <strong>Analysis Agent</strong> scrapes their sites, extracts their messaging, and generates counter-strategies.
                                    </p>
                                </div>

                                {/* Step 3 */}
                                <div className="relative flex flex-col items-center text-center">
                                    <div className="flex items-center justify-center h-24 w-24 rounded-full bg-indigo-50 border-4 border-white shadow-lg mb-6">
                                        <span className="text-4xl">🚀</span>
                                    </div>
                                    <h3 className="text-xl font-bold text-gray-900 mb-3">3. Autonomous Optimization</h3>
                                    <p className="text-gray-600 leading-relaxed">
                                        Deploy with one click. <strong>Self-Healing Agents</strong> fix disapproved ads automatically. <strong>Budget Intelligence</strong> shifts spend to peak hours. <strong>Creative Testing</strong> identifies winners and generates new variations.
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>

                    {/* AI Agents Showcase Section - NEW */}
                    <div className="bg-gradient-to-br from-indigo-900 via-indigo-800 to-purple-900 py-16 sm:py-24">
                        <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                            <div className="mx-auto max-w-2xl lg:text-center mb-12">
                                <p className="text-indigo-300 font-semibold text-sm uppercase tracking-wider">Autonomous AI Agents</p>
                                <h2 className="mt-2 text-3xl sm:text-4xl font-bold tracking-tight text-white">
                                    Your 24/7 Marketing Team
                                </h2>
                                <p className="mt-4 text-lg text-indigo-200">
                                    Six specialized AI agents work around the clock to optimize every aspect of your campaigns.
                                </p>
                            </div>

                            <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                                {/* Agent 1: Competitor Discovery */}
                                <div className="bg-white/10 backdrop-blur-lg rounded-xl p-6 border border-white/20 hover:bg-white/15 transition-colors">
                                    <div className="flex items-center gap-3 mb-4">
                                        <div className="flex h-12 w-12 items-center justify-center rounded-lg bg-indigo-500/30">
                                            <span className="text-2xl">🔍</span>
                                        </div>
                                        <h3 className="text-lg font-bold text-white">Competitor Discovery Agent</h3>
                                    </div>
                                    <p className="text-indigo-200 text-sm leading-relaxed">
                                        Uses Google Search AI to find your real competitors based on your website content. Identifies direct and indirect competitors you might not know about.
                                    </p>
                                    <div className="mt-4 pt-4 border-t border-white/10">
                                        <span className="text-xs text-indigo-300">Runs: Weekly</span>
                                    </div>
                                </div>

                                {/* Agent 2: Competitor Analysis */}
                                <div className="bg-white/10 backdrop-blur-lg rounded-xl p-6 border border-white/20 hover:bg-white/15 transition-colors">
                                    <div className="flex items-center gap-3 mb-4">
                                        <div className="flex h-12 w-12 items-center justify-center rounded-lg bg-indigo-500/30">
                                            <span className="text-2xl">📊</span>
                                        </div>
                                        <h3 className="text-lg font-bold text-white">Competitor Analysis Agent</h3>
                                    </div>
                                    <p className="text-indigo-200 text-sm leading-relaxed">
                                        Scrapes competitor websites, extracts their messaging, value propositions, pricing, and ad copy. Generates counter-strategies for your campaigns.
                                    </p>
                                    <div className="mt-4 pt-4 border-t border-white/10">
                                        <span className="text-xs text-indigo-300">Runs: Weekly</span>
                                    </div>
                                </div>

                                {/* Agent 3: Self-Healing */}
                                <div className="bg-white/10 backdrop-blur-lg rounded-xl p-6 border border-white/20 hover:bg-white/15 transition-colors">
                                    <div className="flex items-center gap-3 mb-4">
                                        <div className="flex h-12 w-12 items-center justify-center rounded-lg bg-green-500/30">
                                            <span className="text-2xl">🩹</span>
                                        </div>
                                        <h3 className="text-lg font-bold text-white">Self-Healing Agent</h3>
                                    </div>
                                    <p className="text-indigo-200 text-sm leading-relaxed">
                                        Monitors for disapproved ads and automatically rewrites them to be policy-compliant. Pauses underperforming ads before they waste budget.
                                    </p>
                                    <div className="mt-4 pt-4 border-t border-white/10">
                                        <span className="text-xs text-green-300">Runs: Every 4 Hours</span>
                                    </div>
                                </div>

                                {/* Agent 4: Budget Intelligence */}
                                <div className="bg-white/10 backdrop-blur-lg rounded-xl p-6 border border-white/20 hover:bg-white/15 transition-colors">
                                    <div className="flex items-center gap-3 mb-4">
                                        <div className="flex h-12 w-12 items-center justify-center rounded-lg bg-yellow-500/30">
                                            <span className="text-2xl">💰</span>
                                        </div>
                                        <h3 className="text-lg font-bold text-white">Budget Intelligence Agent</h3>
                                    </div>
                                    <p className="text-indigo-200 text-sm leading-relaxed">
                                        Dynamically adjusts budgets based on time-of-day and day-of-week performance. Reduces spend at 3am, increases during peak buying hours.
                                    </p>
                                    <div className="mt-4 pt-4 border-t border-white/10">
                                        <span className="text-xs text-yellow-300">Runs: Hourly</span>
                                    </div>
                                </div>

                                {/* Agent 5: Creative Intelligence */}
                                <div className="bg-white/10 backdrop-blur-lg rounded-xl p-6 border border-white/20 hover:bg-white/15 transition-colors">
                                    <div className="flex items-center gap-3 mb-4">
                                        <div className="flex h-12 w-12 items-center justify-center rounded-lg bg-pink-500/30">
                                            <span className="text-2xl">🎨</span>
                                        </div>
                                        <h3 className="text-lg font-bold text-white">Creative Intelligence Agent</h3>
                                    </div>
                                    <p className="text-indigo-200 text-sm leading-relaxed">
                                        Tracks A/B test performance at headline, description, and image level. Identifies winners, kills losers, and generates new variations.
                                    </p>
                                    <div className="mt-4 pt-4 border-t border-white/10">
                                        <span className="text-xs text-pink-300">Runs: Daily</span>
                                    </div>
                                </div>

                                {/* Agent 6: Audience Intelligence */}
                                <div className="bg-white/10 backdrop-blur-lg rounded-xl p-6 border border-white/20 hover:bg-white/15 transition-colors">
                                    <div className="flex items-center gap-3 mb-4">
                                        <div className="flex h-12 w-12 items-center justify-center rounded-lg bg-purple-500/30">
                                            <span className="text-2xl">👥</span>
                                        </div>
                                        <h3 className="text-lg font-bold text-white">Audience Intelligence Agent</h3>
                                    </div>
                                    <p className="text-indigo-200 text-sm leading-relaxed">
                                        Manages Customer Match lists, segments your audience, and recommends lookalike audiences for expansion. Maximizes your first-party data.
                                    </p>
                                    <div className="mt-4 pt-4 border-t border-white/10">
                                        <span className="text-xs text-purple-300">Runs: On-Demand</span>
                                    </div>
                                </div>
                            </div>

                            <div className="mt-12 text-center">
                                <a
                                    href="/register"
                                    className="inline-flex items-center justify-center px-8 py-4 border border-transparent text-lg font-medium rounded-lg text-indigo-900 bg-white hover:bg-indigo-50 shadow-lg transition-colors"
                                >
                                    Put These Agents to Work →
                                </a>
                            </div>
                        </div>
                    </div>

                    {/* Features Section */}
                    <div className="bg-gray-50 py-12 sm:py-16 md:py-20 lg:py-24">
                        <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                            <div className="mx-auto max-w-2xl lg:text-center">
                                <h2 className="text-base font-semibold leading-7 text-indigo-600">Deploy with Confidence</h2>
                                <p className="mt-2 text-2xl sm:text-3xl md:text-4xl font-bold tracking-tight text-gray-900">
                                    Everything you need to launch and optimize your ad campaigns
                                </p>
                                <p className="mt-4 sm:mt-6 text-base sm:text-lg leading-8 text-gray-600">
                                    Our AI agents handle the heavy lifting, from creative generation to performance analysis, so you can focus on your business.
                                </p>
                            </div>
                            <div className="mx-auto mt-12 sm:mt-16 md:mt-20 lg:mt-24 max-w-2xl sm:max-w-none lg:max-w-5xl">
                                <dl className="grid max-w-xl grid-cols-1 sm:grid-cols-2 gap-x-6 sm:gap-x-8 gap-y-8 sm:gap-y-10 lg:max-w-none lg:grid-cols-3 lg:gap-y-16">
                                    <div className="relative pl-16">
                                        <dt className="text-base font-semibold leading-7 text-gray-900">
                                            <div className="absolute left-0 top-0 flex h-10 w-10 items-center justify-center rounded-lg bg-indigo-600">
                                                <svg className="h-6 w-6 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" /></svg>
                                            </div>
                                            Competitive Intelligence
                                        </dt>
                                        <dd className="mt-2 text-base leading-7 text-gray-600">AI agents discover your competitors using Google Search, scrape their websites, analyze their messaging, and generate counter-strategies to help you win.</dd>
                                    </div>
                                    <div className="relative pl-16">
                                        <dt className="text-base font-semibold leading-7 text-gray-900">
                                            <div className="absolute left-0 top-0 flex h-10 w-10 items-center justify-center rounded-lg bg-indigo-600">
                                                <svg className="h-6 w-6 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M4.318 6.318a4.5 4.5 0 000 6.364L12 20.364l7.682-7.682a4.5 4.5 0 00-6.364-6.364L12 7.636l-1.318-1.318a4.5 4.5 0 00-6.364 0z" /></svg>
                                            </div>
                                            Self-Healing Campaigns
                                        </dt>
                                        <dd className="mt-2 text-base leading-7 text-gray-600">Disapproved ad? Our AI automatically rewrites it to be policy-compliant and resubmits. Underperforming ads are paused before they waste your budget.</dd>
                                    </div>
                                    <div className="relative pl-16">
                                        <dt className="text-base font-semibold leading-7 text-gray-900">
                                            <div className="absolute left-0 top-0 flex h-10 w-10 items-center justify-center rounded-lg bg-indigo-600">
                                                <svg className="h-6 w-6 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                                            </div>
                                            Budget Intelligence
                                        </dt>
                                        <dd className="mt-2 text-base leading-7 text-gray-600">Smart budget shifting based on time-of-day and day-of-week performance. Reduce spend at 3am, increase during peak buying hours—automatically.</dd>
                                    </div>
                                    <div className="relative pl-16">
                                        <dt className="text-base font-semibold leading-7 text-gray-900">
                                            <div className="absolute left-0 top-0 flex h-10 w-10 items-center justify-center rounded-lg bg-indigo-600">
                                                <svg className="h-6 w-6 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z" /></svg>
                                            </div>
                                            Creative A/B Testing
                                        </dt>
                                        <dd className="mt-2 text-base leading-7 text-gray-600">Track performance at the headline, description, and image level. AI identifies winners, kills losers, and generates new variations based on what's working.</dd>
                                    </div>
                                    <div className="relative pl-16">
                                        <dt className="text-base font-semibold leading-7 text-gray-900">
                                            <div className="absolute left-0 top-0 flex h-10 w-10 items-center justify-center rounded-lg bg-indigo-600">
                                                <svg className="h-6 w-6 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z" /></svg>
                                            </div>
                                            Audience Intelligence
                                        </dt>
                                        <dd className="mt-2 text-base leading-7 text-gray-600">Upload your customer emails for Customer Match targeting. AI segments your audience and recommends lookalike audiences for expansion.</dd>
                                    </div>
                                    <div className="relative pl-16">
                                        <dt className="text-base font-semibold leading-7 text-gray-900">
                                            <div className="absolute left-0 top-0 flex h-10 w-10 items-center justify-center rounded-lg bg-indigo-600">
                                                <svg className="h-6 w-6 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M7 21a4 4 0 01-4-4V5a2 2 0 012-2h4a2 2 0 012 2v12a4 4 0 01-4 4zm0 0h12a2 2 0 002-2v-4a2 2 0 00-2-2h-2.343M11 7.343l1.657-1.657a2 2 0 012.828 0l2.829 2.829a2 2 0 010 2.828l-8.486 8.485M7 17h.01" /></svg>
                                            </div>
                                            Vision AI Brand Extraction
                                        </dt>
                                        <dd className="mt-2 text-base leading-7 text-gray-600">AI screenshots your website and extracts colors, fonts, and brand voice. Every ad stays perfectly on-brand without manual input.</dd>
                                    </div>
                                    <div className="relative pl-16">
                                        <dt className="text-base font-semibold leading-7 text-gray-900">
                                            <div className="absolute left-0 top-0 flex h-10 w-10 items-center justify-center rounded-lg bg-indigo-600">
                                                <svg className="h-6 w-6 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 6V4m0 2a2 2 0 100 4m0-4a2 2 0 110 4m-6 8a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4m6 6v10m6-2a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4" /></svg>
                                            </div>
                                            Search, Display, Video, PMax
                                        </dt>
                                        <dd className="mt-2 text-base leading-7 text-gray-600">Full Google Ads support including Search campaigns, Display ads, Video campaigns, and Performance Max with AI-generated assets.</dd>
                                    </div>
                                    <div className="relative pl-16">
                                        <dt className="text-base font-semibold leading-7 text-gray-900">
                                            <div className="absolute left-0 top-0 flex h-10 w-10 items-center justify-center rounded-lg bg-indigo-600">
                                                <svg className="h-6 w-6 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                                            </div>
                                            Conversion Tracking & GTM
                                        </dt>
                                        <dd className="mt-2 text-base leading-7 text-gray-600">Automatic conversion tracking setup with Google Tag Manager integration. We create and deploy tags so you can measure ROI from day one.</dd>
                                    </div>
                                    <div className="relative pl-16">
                                        <dt className="text-base font-semibold leading-7 text-gray-900">
                                            <div className="absolute left-0 top-0 flex h-10 w-10 items-center justify-center rounded-lg bg-indigo-600">
                                                <svg className="h-6 w-6 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M9 19l3 3m0 0l3-3m-3 3v-6" /></svg>
                                            </div>
                                            Generate for Free
                                        </dt>
                                        <dd className="mt-2 text-base leading-7 text-gray-600">Sign up free and generate unlimited ad copy, images, and videos. Only pay when you're ready to deploy campaigns.</dd>
                                    </div>
                                </dl>
                            </div>
                        </div>
                    </div>

                    {/* Case Studies Section */}
                    <div className="bg-white py-12 sm:py-16 md:py-20 lg:py-24">
                        <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                            <div className="mx-auto max-w-2xl lg:text-center">
                                <h2 className="text-2xl sm:text-3xl md:text-4xl font-bold tracking-tight text-gray-900">From Our Customers</h2>
                                <p className="mt-4 sm:mt-6 text-base sm:text-lg leading-8 text-gray-600">
                                    See how businesses like yours are succeeding with sitetospend.
                                </p>
                            </div>
                            <div className="mx-auto mt-12 sm:mt-16 md:mt-20 grid max-w-2xl grid-cols-1 gap-6 sm:gap-8 text-sm leading-6 text-gray-900 sm:grid-cols-2 xl:mx-0 xl:max-w-none xl:grid-cols-3\">
                                <div className="relative rounded-2xl bg-gray-50 p-6 shadow-sm ring-1 ring-gray-900/5 hover:shadow-md transition-shadow">
                                    <div className="flex gap-1 mb-4">
                                        {[...Array(5)].map((_, i) => <span key={i} className="text-yellow-400">★</span>)}
                                    </div>
                                    <div className="text-lg font-medium">
                                        <p>"sitetospend has been a game-changer for our marketing. We've seen a 30% increase in conversions since we started using their AI agents."</p>
                                    </div>
                                    <div className="mt-6 font-semibold">Sarah L.</div>
                                    <div className="text-sm text-gray-600">CEO, Growing e-commerce brand</div>
                                </div>
                                <div className="relative rounded-2xl bg-gray-50 p-6 shadow-sm ring-1 ring-gray-900/5 hover:shadow-md transition-shadow">
                                    <div className="flex gap-1 mb-4">
                                        {[...Array(5)].map((_, i) => <span key={i} className="text-yellow-400">★</span>)}
                                    </div>
                                    <div className="text-lg font-medium">
                                        <p>"The ability to generate and test so many different creatives so quickly is incredible. Much better results for a fraction of the cost."</p>
                                    </div>
                                    <div className="mt-6 font-semibold">Mike R.</div>
                                    <div className="text-sm text-gray-600">Founder, SaaS startup</div>
                                </div>
                                <div className="relative rounded-2xl bg-gray-50 p-6 shadow-sm ring-1 ring-gray-900/5 hover:shadow-md transition-shadow">
                                    <div className="flex gap-1 mb-4">
                                        {[...Array(5)].map((_, i) => <span key={i} className="text-yellow-400">★</span>)}
                                    </div>
                                    <div className="text-lg font-medium">
                                        <p>"As a small business owner, I don't have time to manage campaigns. sitetospend's AI agents do it all, and results have been fantastic."</p>
                                    </div>
                                    <div className="mt-6 font-semibold">Jessica B.</div>
                                    <div className="text-sm text-gray-600">Owner, Local service business</div>
                                </div>
                            </div>
                        </div>
                    </div>

                    {/* Pricing Section - Enhanced */}
                    <div className="bg-gradient-to-b from-gray-50 to-white py-16 sm:py-24">
                        <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
                            <div className="text-center mb-16">
                                <h2 className="text-3xl font-extrabold text-gray-900 sm:text-4xl">
                                    Simple, Transparent Pricing
                                </h2>
                                <p className="mt-4 text-xl text-gray-500">
                                    Generate unlimited collateral. Only pay when you're ready to publish.
                                </p>
                            </div>

                            {/* Comparison Table */}
                            <div className="max-w-4xl mx-auto mb-16 overflow-hidden rounded-lg border border-gray-200 shadow-sm">
                                <table className="min-w-full divide-y divide-gray-200">
                                    <thead className="bg-gray-50">
                                        <tr>
                                            <th scope="col" className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider"></th>
                                            <th scope="col" className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Traditional Agency</th>
                                            <th scope="col" className="px-6 py-3 text-left text-xs font-bold text-indigo-600 uppercase tracking-wider">Spectra AI</th>
                                        </tr>
                                    </thead>
                                    <tbody className="bg-white divide-y divide-gray-200">
                                        <tr>
                                            <td className="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">Cost</td>
                                            <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-500">$2,500 - $5,000 / month</td>
                                            <td className="px-6 py-4 whitespace-nowrap text-sm font-bold text-indigo-600">From $99 / month</td>
                                        </tr>
                                        <tr>
                                            <td className="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">Setup Time</td>
                                            <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-500">2-4 Weeks</td>
                                            <td className="px-6 py-4 whitespace-nowrap text-sm font-bold text-indigo-600">&lt; 5 Minutes</td>
                                        </tr>
                                        <tr>
                                            <td className="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">Brand Guidelines</td>
                                            <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-500">Manual PDF creation (billed extra)</td>
                                            <td className="px-6 py-4 whitespace-nowrap text-sm font-bold text-indigo-600">Instant Vision AI Extraction</td>
                                        </tr>
                                        <tr>
                                            <td className="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">Creatives</td>
                                            <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-500">Limited revisions</td>
                                            <td className="px-6 py-4 whitespace-nowrap text-sm font-bold text-indigo-600">Unlimited AI Generation</td>
                                        </tr>
                                        <tr>
                                            <td className="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">Optimization</td>
                                            <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-500">Weekly manual checks</td>
                                            <td className="px-6 py-4 whitespace-nowrap text-sm font-bold text-indigo-600">24/7 Real-time Agent adjustments</td>
                                        </tr>
                                        <tr>
                                            <td className="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">Competitor Analysis</td>
                                            <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-500">Manual research (extra hours)</td>
                                            <td className="px-6 py-4 whitespace-nowrap text-sm font-bold text-indigo-600">Automatic AI Discovery & Counter-Strategy</td>
                                        </tr>
                                        <tr>
                                            <td className="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">Campaign Fixes</td>
                                            <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-500">Wait for account manager response</td>
                                            <td className="px-6 py-4 whitespace-nowrap text-sm font-bold text-indigo-600">Self-Healing AI (instant fixes)</td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>

                            <div className="grid grid-cols-1 md:grid-cols-3 gap-8 max-w-7xl mx-auto">
                                {/* Starter Tier */}
                                <div className="rounded-lg border border-gray-200 p-8 bg-white flex flex-col">
                                    <h3 className="text-2xl font-bold text-gray-900">Starter</h3>
                                    <p className="mt-2 text-sm text-gray-500">For local businesses and early-stage startups.</p>
                                    <div className="mt-4 text-gray-900">
                                        <span className="text-4xl font-extrabold">$99</span>
                                        <span className="text-xl font-medium">/month</span>
                                    </div>
                                    <p className="mt-4 text-sm text-gray-500">7-day free trial. Cancel anytime.</p>
                                    <p className="mt-2 text-xs text-gray-400">Perfect for: Spending up to $3,000/mo on ads.</p>
                                    <ul className="mt-8 space-y-4 flex-grow">
                                        <li className="flex items-start">
                                            <svg className="h-6 w-6 text-green-500 mr-3 flex-shrink-0" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M5 13l4 4L19 7" />
                                            </svg>
                                            <p className="text-gray-700">1 Brand Identity (Vision AI Extraction)</p>
                                        </li>
                                        <li className="flex items-start">
                                            <svg className="h-6 w-6 text-green-500 mr-3 flex-shrink-0" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M5 13l4 4L19 7" />
                                            </svg>
                                            <p className="text-gray-700">Google & Facebook Deployment</p>
                                        </li>
                                        <li className="flex items-start">
                                            <svg className="h-6 w-6 text-green-500 mr-3 flex-shrink-0" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M5 13l4 4L19 7" />
                                            </svg>
                                            <p className="text-gray-700">3 Landing Page CRO Audits</p>
                                        </li>
                                        <li className="flex items-start">
                                            <svg className="h-6 w-6 text-green-500 mr-3 flex-shrink-0" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M5 13l4 4L19 7" />
                                            </svg>
                                            <p className="text-gray-700">Standard AI Copy & Image Generation</p>
                                        </li>
                                        <li className="flex items-start">
                                            <svg className="h-6 w-6 text-green-500 mr-3 flex-shrink-0" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M5 13l4 4L19 7" />
                                            </svg>
                                            <p className="text-gray-700">Weekly Performance Optimization</p>
                                        </li>
                                        <li className="flex items-start">
                                            <svg className="h-6 w-6 text-green-500 mr-3 flex-shrink-0" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M5 13l4 4L19 7" />
                                            </svg>
                                            <p className="text-gray-700">Basic Email Support</p>
                                        </li>
                                    </ul>
                                    <div className="mt-10">
                                        <a
                                            href="/register"
                                            className="block w-full text-center rounded-lg border-2 border-gray-300 px-6 py-3 text-base font-medium text-gray-900 hover:border-gray-400"
                                        >
                                            Start Free Trial
                                        </a>
                                    </div>
                                </div>

                                {/* Growth Tier */}
                                <div className="rounded-lg border-2 border-indigo-600 p-8 bg-indigo-50 shadow-lg relative flex flex-col">
                                    <div className="absolute -top-4 left-1/2 transform -translate-x-1/2 bg-indigo-600 text-white px-3 py-1 text-xs font-semibold rounded-full">
                                        MOST POPULAR
                                    </div>
                                    <h3 className="text-2xl font-bold text-gray-900">Growth</h3>
                                    <p className="mt-2 text-sm text-gray-500">For e-commerce brands ready to scale.</p>
                                    <div className="mt-4 text-gray-900">
                                        <span className="text-4xl font-extrabold">$249</span>
                                        <span className="text-xl font-medium">/month</span>
                                    </div>
                                    <p className="mt-4 text-sm text-gray-600">7-day free trial. Cancel anytime.</p>
                                    <p className="mt-2 text-xs text-gray-500">Perfect for: Spending up to $25,000/mo on ads.</p>
                                    <ul className="mt-8 space-y-4 flex-grow">
                                        <li className="flex items-start">
                                            <svg className="h-6 w-6 text-green-500 mr-3 flex-shrink-0" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M5 13l4 4L19 7" />
                                            </svg>
                                            <p className="text-gray-700">Everything in Starter, plus:</p>
                                        </li>
                                        <li className="flex items-start">
                                            <svg className="h-6 w-6 text-green-500 mr-3 flex-shrink-0" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M5 13l4 4L19 7" />
                                            </svg>
                                            <p className="text-gray-700">Unlimited Brand Identities</p>
                                        </li>
                                        <li className="flex items-start">
                                            <svg className="h-6 w-6 text-green-500 mr-3 flex-shrink-0" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M5 13l4 4L19 7" />
                                            </svg>
                                            <p className="text-gray-700">Unlimited Landing Page CRO Audits</p>
                                        </li>
                                        <li className="flex items-start">
                                            <svg className="h-6 w-6 text-green-500 mr-3 flex-shrink-0" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M5 13l4 4L19 7" />
                                            </svg>
                                            <p className="text-gray-700">Advanced Creative Suite (Video & Carousel)</p>
                                        </li>
                                        <li className="flex items-start">
                                            <svg className="h-6 w-6 text-green-500 mr-3 flex-shrink-0" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M5 13l4 4L19 7" />
                                            </svg>
                                            <p className="text-gray-700">Daily Performance Optimization</p>
                                        </li>
                                        <li className="flex items-start">
                                            <svg className="h-6 w-6 text-green-500 mr-3 flex-shrink-0" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M5 13l4 4L19 7" />
                                            </svg>
                                            <p className="text-gray-700">Strategy Agent "War Room" Access</p>
                                        </li>
                                        <li className="flex items-start">
                                            <svg className="h-6 w-6 text-green-500 mr-3 flex-shrink-0" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M5 13l4 4L19 7" />
                                            </svg>
                                            <p className="text-gray-700">Priority Support</p>
                                        </li>
                                    </ul>
                                    <div className="mt-10">
                                        <a
                                            href="/register"
                                            className="block w-full text-center rounded-lg bg-indigo-600 px-6 py-3 text-base font-medium text-white hover:bg-indigo-700 shadow-lg"
                                        >
                                            Start Scaling Now
                                        </a>
                                    </div>
                                </div>

                                {/* Agency Tier */}
                                <div className="rounded-lg border border-gray-200 p-8 bg-white flex flex-col">
                                    <h3 className="text-2xl font-bold text-gray-900">Agency</h3>
                                    <p className="mt-2 text-sm text-gray-500">For high-volume advertisers and marketing agencies.</p>
                                    <div className="mt-4 text-gray-900">
                                        <span className="text-4xl font-extrabold">$499</span>
                                        <span className="text-xl font-medium">/month</span>
                                    </div>
                                    <p className="mt-4 text-sm text-gray-500">No contracts.</p>
                                    <p className="mt-2 text-xs text-gray-400">Perfect for: Unlimited Ad Spend.</p>
                                    <ul className="mt-8 space-y-4 flex-grow">
                                        <li className="flex items-start">
                                            <svg className="h-6 w-6 text-green-500 mr-3 flex-shrink-0" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M5 13l4 4L19 7" />
                                            </svg>
                                            <p className="text-gray-700">Everything in Growth, plus:</p>
                                        </li>
                                        <li className="flex items-start">
                                            <svg className="h-6 w-6 text-green-500 mr-3 flex-shrink-0" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M5 13l4 4L19 7" />
                                            </svg>
                                            <p className="text-gray-700">Multi-Client Management (10 sub-accounts)</p>
                                        </li>
                                        <li className="flex items-start">
                                            <svg className="h-6 w-6 text-green-500 mr-3 flex-shrink-0" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M5 13l4 4L19 7" />
                                            </svg>
                                            <p className="text-gray-700">White-Label Reports</p>
                                        </li>
                                        <li className="flex items-start">
                                            <svg className="h-6 w-6 text-green-500 mr-3 flex-shrink-0" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M5 13l4 4L19 7" />
                                            </svg>
                                            <p className="text-gray-700">Real-Time Bidding</p>
                                        </li>
                                        <li className="flex items-start">
                                            <svg className="h-6 w-6 text-green-500 mr-3 flex-shrink-0" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M5 13l4 4L19 7" />
                                            </svg>
                                            <p className="text-gray-700">Dedicated Account Success Manager</p>
                                        </li>
                                        <li className="flex items-start">
                                            <svg className="h-6 w-6 text-green-500 mr-3 flex-shrink-0" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M5 13l4 4L19 7" />
                                            </svg>
                                            <p className="text-gray-700">Early Access to Beta Features</p>
                                        </li>
                                    </ul>
                                    <div className="mt-10">
                                        <a
                                            href="/contact"
                                            className="block w-full text-center rounded-lg border-2 border-gray-300 px-6 py-3 text-base font-medium text-gray-900 hover:border-gray-400"
                                        >
                                            Contact Sales
                                        </a>
                                    </div>
                                </div>
                            </div>

                            {/* Trust Seals */}
                            <div className="mt-12 flex justify-center gap-8 text-sm text-gray-500">
                                <div className="flex items-center gap-2">
                                    <span>🔒</span> Secure Stripe Payment
                                </div>
                                <div className="flex items-center gap-2">
                                    <span>⚡</span> Approved Google Partner
                                </div>
                                <div className="flex items-center gap-2">
                                    <span>🛡️</span> Data Encrypted & Private
                                </div>
                            </div>
                        </div>
                    </div>

                    {/* FAQ Section */}
                    <div className="bg-white py-16 sm:py-24">
                        <div className="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8">
                            <div className="text-center mb-12">
                                <h2 className="text-3xl font-extrabold text-gray-900 sm:text-4xl">
                                    Frequently Asked Questions
                                </h2>
                                <p className="mt-4 text-lg text-gray-500">
                                    Get answers to common questions about how sitetospend works.
                                </p>
                            </div>

                            <div className="space-y-4">
                                {[
                                    {
                                        question: "Does the subscription price include my ad budget?",
                                        answer: "No. Your subscription covers the Spectra AI platform, the creative generation, and the autonomous management agents. You will connect your own credit card to Google/Facebook, so you pay the ad networks directly for your media spend. This ensures total transparency—we never mark up your ad costs."
                                    },
                                    {
                                        question: "How does ad spend billing work?",
                                        answer: "When you launch your first campaign, we charge 7 days of estimated ad spend upfront as credit. This ensures your campaigns have guaranteed funding from day one. We then bill your actual daily spend each morning at 6 AM, deducting from your credit balance. When your balance gets low (less than 3 days remaining), we automatically top it up to keep your campaigns running smoothly."
                                    },
                                    {
                                        question: "What happens if a payment fails?",
                                        answer: "We give you a 24-hour grace period to update your payment method. If the payment still fails after Day 1, we reduce campaign budgets by 50% to minimize spend. If payment hasn't been resolved by Day 2, we pause all campaigns to protect both parties. Once payment is successful, campaigns are automatically resumed at full budget—no action needed from you."
                                    },
                                    {
                                        question: "How do I update my payment method?",
                                        answer: "You can update your payment method anytime in your dashboard under Billing → Ad Spend. If a payment has failed, you'll see a 'Retry Payment' button that will immediately attempt to charge your new card and resume your campaigns if successful."
                                    },
                                    {
                                        question: "What do the AI agents actually do?",
                                        answer: "Our 6 autonomous agents work 24/7: The Competitor Discovery Agent finds your competitors via Google Search, the Analysis Agent scrapes their websites for messaging insights, the Self-Healing Agent fixes disapproved ads automatically, the Budget Intelligence Agent optimizes spend by time-of-day, the Creative Intelligence Agent A/B tests your ads and generates new variations, and the Audience Intelligence Agent manages your customer lists."
                                    },
                                    {
                                        question: "How does competitor analysis work?",
                                        answer: "Every week, our AI reads your website content to understand your business, then uses Google Search to discover real competitors in your space. It scrapes their websites, extracts their messaging, value propositions, and pricing, then generates counter-strategies with specific ad copy recommendations to help you win."
                                    },
                                    {
                                        question: "What happens if my ad gets disapproved?",
                                        answer: "Our Self-Healing Agent automatically detects disapproved ads and rewrites the copy to be policy-compliant while maintaining your brand voice. It then resubmits the ad—all without any action needed from you. Underperforming ads are also automatically paused to protect your budget."
                                    },
                                    {
                                        question: "How does the \"Vision AI\" know my brand?",
                                        answer: "We use Gemini Pro Vision to analyze screenshots of your website. It instantly extracts your hex codes, fonts, tone of voice, and visual style to ensure every ad we generate looks exactly like it came from your internal design team."
                                    },
                                    {
                                        question: "Can I switch plans later?",
                                        answer: "Absolutely. You can upgrade or downgrade at any time from your dashboard. If you exceed the ad spend limit of your tier, we'll simply notify you to upgrade to keep your campaigns running at peak performance."
                                    }
                                ].map((faq, index) => (
                                    <div key={index} className="border border-gray-200 rounded-lg">
                                        <button
                                            onClick={() => setOpenFAQ(openFAQ === index ? null : index)}
                                            className="w-full px-6 py-4 text-left flex items-center justify-between hover:bg-gray-50 transition-colors"
                                        >
                                            <h3 className="text-lg font-bold text-gray-900">{faq.question}</h3>
                                            <svg
                                                className={`h-6 w-6 text-indigo-600 flex-shrink-0 transition-transform ${
                                                    openFAQ === index ? 'rotate-180' : ''
                                                }`}
                                                xmlns="http://www.w3.org/2000/svg"
                                                fill="none"
                                                viewBox="0 0 24 24"
                                                stroke="currentColor"
                                            >
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

                    {/* Final CTA Section */}
                    <div className="bg-gradient-to-r from-indigo-600 to-indigo-800 py-16 sm:py-24">
                        <div className="max-w-4xl mx-auto text-center px-4 sm:px-6 lg:px-8">
                            <h2 className="text-4xl font-extrabold text-white sm:text-5xl">
                                Ready to transform your marketing?
                            </h2>
                            <p className="mt-6 text-xl text-indigo-100">
                                Join hundreds of marketing teams creating smarter, faster campaigns with AI-powered optimization.
                            </p>
                            <div className="mt-10 flex flex-col sm:flex-row gap-4 justify-center">
                                <a
                                    href="/register"
                                    className="inline-flex items-center justify-center px-8 py-4 border border-transparent text-lg font-medium rounded-lg text-indigo-600 bg-white hover:bg-gray-50 shadow-lg"
                                >
                                    Get Started Free
                                </a>
                                <a
                                    href="/login"
                                    className="inline-flex items-center justify-center px-8 py-4 border-2 border-white text-lg font-medium rounded-lg text-white hover:bg-indigo-700"
                                >
                                    Sign In
                                </a>
                            </div>
                            <p className="mt-8 text-indigo-100">
                                ✓ Free forever tier · ✓ No credit card required · ✓ Deploy in minutes
                            </p>
                        </div>
                    </div>
                </main>

                <Footer />
            </div>
        </>
    );
}
