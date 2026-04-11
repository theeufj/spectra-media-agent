import React from 'react';
import { Head, Link } from '@inertiajs/react';
import Header from '@/Components/Header';
import Footer from '@/Components/Footer';

export default function Features({ auth }) {
    return (
        <>
            <Head>
                <title>Features - AI Marketing Agents & Automation | sitetospend</title>
                <meta name="description" content="Discover sitetospend's 6 autonomous AI agents: competitor discovery, self-healing campaigns, budget intelligence, creative A/B testing, audience management, and Vision AI brand extraction." />
                <meta property="og:title" content="Features — 6 Autonomous AI Marketing Agents | sitetospend" />
                <meta property="og:description" content="Competitor discovery, self-healing campaigns, budget intelligence, creative testing, audience management, and Vision AI brand extraction—all on autopilot." />
                <meta name="twitter:title" content="Features — 6 Autonomous AI Marketing Agents | sitetospend" />
                <meta name="twitter:description" content="Competitor discovery, self-healing campaigns, budget intelligence, creative testing, audience management, and Vision AI brand extraction." />
            </Head>
            <div className="min-h-screen bg-gray-50 text-gray-800">
                <Header auth={auth} />

                <main>
                    {/* Hero */}
                    <div className="bg-gradient-to-b from-flame-orange-50 to-white py-16 sm:py-24">
                        <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 text-center">
                            <p className="text-sm font-semibold text-flame-orange-600 uppercase tracking-wider">Platform Features</p>
                            <h1 className="mt-3 text-4xl sm:text-5xl font-extrabold tracking-tight text-gray-900">
                                Everything You Need to Win at Paid Ads
                            </h1>
                            <p className="mt-6 max-w-2xl mx-auto text-lg text-gray-500">
                                Six autonomous AI agents, full Google Ads support, Vision AI brand extraction, and conversion tracking—all working 24/7 so you don't have to.
                            </p>
                        </div>
                    </div>

                    {/* Platforms Section */}
                    <div className="bg-white py-16 sm:py-24">
                        <div className="mx-auto max-w-7xl px-6 lg:px-8">
                            <div className="mx-auto max-w-2xl lg:text-center">
                                <h2 className="text-3xl font-bold tracking-tight text-gray-900 sm:text-4xl">One-Click Deployment to All Your Platforms</h2>
                                <p className="mt-6 text-lg leading-8 text-gray-600">
                                    Connect your accounts and let our AI agents handle the rest. We're constantly adding new platforms to our roster.
                                </p>
                            </div>
                            <div className="mt-16 flex justify-center">
                                <div className="flex flex-wrap justify-center gap-8">
                                    {['Google Ads', 'Meta Ads', 'Microsoft Ads'].map((platform) => (
                                        <div key={platform} className="flex flex-col items-center text-center">
                                            <div className="flex h-24 w-24 items-center justify-center rounded-full bg-green-100">
                                                <svg className="h-12 w-12 text-green-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                                            </div>
                                            <p className="mt-4 font-semibold text-gray-900">{platform}</p>
                                            <p className="text-sm text-green-600 font-medium">Available Now</p>
                                        </div>
                                    ))}
                                    {['Instagram Ads', 'Reddit Ads'].map((platform) => (
                                        <div key={platform} className="flex flex-col items-center text-center grayscale">
                                            <div className="relative flex h-24 w-24 items-center justify-center rounded-full bg-gray-100">
                                                <span className="absolute -top-1 -right-1 inline-flex items-center rounded-full bg-yellow-50 px-2 py-1 text-xs font-medium text-yellow-800 ring-1 ring-inset ring-yellow-600/20">Coming Soon</span>
                                                <svg className="h-12 w-12 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                                            </div>
                                            <p className="mt-4 font-semibold text-gray-900">{platform}</p>
                                        </div>
                                    ))}
                                </div>
                            </div>
                        </div>
                    </div>

                    {/* AI Agents Showcase */}
                    <div className="bg-gradient-to-br from-flame-orange-900 via-flame-orange-800 to-purple-900 py-16 sm:py-24">
                        <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                            <div className="mx-auto max-w-2xl lg:text-center mb-12">
                                <p className="text-flame-orange-300 font-semibold text-sm uppercase tracking-wider">Autonomous AI Agents</p>
                                <h2 className="mt-2 text-3xl sm:text-4xl font-bold tracking-tight text-white">Your 24/7 Marketing Team</h2>
                                <p className="mt-4 text-lg text-flame-orange-200">
                                    Six specialized AI agents work around the clock to optimize every aspect of your campaigns.
                                </p>
                            </div>

                            <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                                {[
                                    { icon: '🔍', color: 'flame-orange', name: 'Competitor Discovery Agent', desc: 'Uses Google Search AI to find your real competitors based on your website content. Identifies direct and indirect competitors you might not know about.', freq: 'Weekly' },
                                    { icon: '📊', color: 'flame-orange', name: 'Competitor Analysis Agent', desc: 'Scrapes competitor websites, extracts their messaging, value propositions, pricing, and ad copy. Generates counter-strategies for your campaigns.', freq: 'Weekly' },
                                    { icon: '🩹', color: 'green', name: 'Self-Healing Agent', desc: 'Monitors for disapproved ads and automatically rewrites them to be policy-compliant. Pauses underperforming ads before they waste budget.', freq: 'Every 4 Hours' },
                                    { icon: '💰', color: 'yellow', name: 'Budget Intelligence Agent', desc: 'Dynamically adjusts budgets based on time-of-day and day-of-week performance. Reduces spend at 3am, increases during peak buying hours.', freq: 'Hourly' },
                                    { icon: '🎨', color: 'pink', name: 'Creative Intelligence Agent', desc: 'Tracks A/B test performance at headline, description, and image level. Identifies winners, kills losers, and generates new variations.', freq: 'Daily' },
                                    { icon: '👥', color: 'purple', name: 'Audience Intelligence Agent', desc: 'Manages Customer Match lists, segments your audience, and recommends lookalike audiences for expansion. Maximizes your first-party data.', freq: 'On-Demand' },
                                ].map((agent) => (
                                    <div key={agent.name} className="bg-white/10 backdrop-blur-lg rounded-xl p-6 border border-white/20 hover:bg-white/15 transition-colors">
                                        <div className="flex items-center gap-3 mb-4">
                                            <div className={`flex h-12 w-12 items-center justify-center rounded-lg bg-${agent.color}-500/30`}>
                                                <span className="text-2xl">{agent.icon}</span>
                                            </div>
                                            <h3 className="text-lg font-bold text-white">{agent.name}</h3>
                                        </div>
                                        <p className="text-flame-orange-200 text-sm leading-relaxed">{agent.desc}</p>
                                        <div className="mt-4 pt-4 border-t border-white/10">
                                            <span className={`text-xs text-${agent.color}-300`}>Runs: {agent.freq}</span>
                                        </div>
                                    </div>
                                ))}
                            </div>

                            <div className="mt-12 text-center">
                                <a href="/register" className="inline-flex items-center justify-center px-8 py-4 border border-transparent text-lg font-medium rounded-lg text-flame-orange-900 bg-white hover:bg-flame-orange-50 shadow-lg transition-colors">
                                    Put These Agents to Work →
                                </a>
                            </div>
                        </div>
                    </div>

                    {/* Features Grid */}
                    <div className="bg-gray-50 py-12 sm:py-16 md:py-20 lg:py-24">
                        <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                            <div className="mx-auto max-w-2xl lg:text-center">
                                <h2 className="text-base font-semibold leading-7 text-flame-orange-600">Deploy with Confidence</h2>
                                <p className="mt-2 text-2xl sm:text-3xl md:text-4xl font-bold tracking-tight text-gray-900">
                                    Everything you need to launch and optimize your ad campaigns
                                </p>
                                <p className="mt-4 sm:mt-6 text-base sm:text-lg leading-8 text-gray-600">
                                    Our AI agents handle the heavy lifting, from creative generation to performance analysis, so you can focus on your business.
                                </p>
                            </div>
                            <div className="mx-auto mt-12 sm:mt-16 md:mt-20 lg:mt-24 max-w-2xl sm:max-w-none lg:max-w-5xl">
                                <dl className="grid max-w-xl grid-cols-1 sm:grid-cols-2 gap-x-6 sm:gap-x-8 gap-y-8 sm:gap-y-10 lg:max-w-none lg:grid-cols-3 lg:gap-y-16">
                                    {[
                                        { title: 'Competitive Intelligence', desc: 'AI agents discover your competitors using Google Search, scrape their websites, analyze their messaging, and generate counter-strategies to help you win.', icon: 'M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z' },
                                        { title: 'Self-Healing Campaigns', desc: "Disapproved ad? Our AI automatically rewrites it to be policy-compliant and resubmits. Underperforming ads are paused before they waste your budget.", icon: 'M4.318 6.318a4.5 4.5 0 000 6.364L12 20.364l7.682-7.682a4.5 4.5 0 00-6.364-6.364L12 7.636l-1.318-1.318a4.5 4.5 0 00-6.364 0z' },
                                        { title: 'Budget Intelligence', desc: 'Smart budget shifting based on time-of-day and day-of-week performance. Reduce spend at 3am, increase during peak buying hours—automatically.', icon: 'M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z' },
                                        { title: 'Creative A/B Testing', desc: "Track performance at the headline, description, and image level. AI identifies winners, kills losers, and generates new variations based on what's working.", icon: 'M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z' },
                                        { title: 'Audience Intelligence', desc: 'Upload your customer emails for Customer Match targeting. AI segments your audience and recommends lookalike audiences for expansion.', icon: 'M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z' },
                                        { title: 'Vision AI Brand Extraction', desc: 'AI screenshots your website and extracts colors, fonts, and brand voice. Every ad stays perfectly on-brand without manual input.', icon: 'M7 21a4 4 0 01-4-4V5a2 2 0 012-2h4a2 2 0 012 2v12a4 4 0 01-4 4zm0 0h12a2 2 0 002-2v-4a2 2 0 00-2-2h-2.343M11 7.343l1.657-1.657a2 2 0 012.828 0l2.829 2.829a2 2 0 010 2.828l-8.486 8.485M7 17h.01' },
                                        { title: 'Search, Display, Video, PMax', desc: 'Full Google Ads support including Search campaigns, Display ads, Video campaigns, and Performance Max with AI-generated assets.', icon: 'M12 6V4m0 2a2 2 0 100 4m0-4a2 2 0 110 4m-6 8a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4m6 6v10m6-2a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4' },
                                        { title: 'Conversion Tracking & GTM', desc: 'Automatic conversion tracking setup with Google Tag Manager integration. We create and deploy tags so you can measure ROI from day one.', icon: 'M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z' },
                                        { title: 'Try Before You Buy', desc: "Sign up free and test our platform with 3 brand sources, 4 images per campaign (watermarked), and unlimited ad copy. Upgrade when you're ready to deploy live campaigns.", icon: 'M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M9 19l3 3m0 0l3-3m-3 3v-6' },
                                    ].map((feature) => (
                                        <div key={feature.title} className="relative pl-16">
                                            <dt className="text-base font-semibold leading-7 text-gray-900">
                                                <div className="absolute left-0 top-0 flex h-10 w-10 items-center justify-center rounded-lg bg-flame-orange-600">
                                                    <svg className="h-6 w-6 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d={feature.icon} /></svg>
                                                </div>
                                                {feature.title}
                                            </dt>
                                            <dd className="mt-2 text-base leading-7 text-gray-600">{feature.desc}</dd>
                                        </div>
                                    ))}
                                </dl>
                            </div>
                        </div>
                    </div>

                    {/* CTA */}
                    <div className="bg-gradient-to-r from-flame-orange-600 to-flame-orange-800 py-16 sm:py-24">
                        <div className="max-w-4xl mx-auto text-center px-4 sm:px-6 lg:px-8">
                            <h2 className="text-4xl font-extrabold text-white sm:text-5xl">
                                See All Features in Action
                            </h2>
                            <p className="mt-6 text-xl text-flame-orange-100">
                                Sign up free and explore everything sitetospend has to offer—no credit card required.
                            </p>
                            <div className="mt-10 flex flex-col sm:flex-row gap-4 justify-center">
                                <a href="/register" className="inline-flex items-center justify-center px-8 py-4 border border-transparent text-lg font-medium rounded-lg text-flame-orange-600 bg-white hover:bg-gray-50 shadow-lg">
                                    Get Started Free
                                </a>
                                <Link href="/pricing" className="inline-flex items-center justify-center px-8 py-4 border-2 border-white text-lg font-medium rounded-lg text-white hover:bg-flame-orange-700">
                                    View Pricing
                                </Link>
                            </div>
                        </div>
                    </div>
                </main>

                <Footer />
            </div>
        </>
    );
}
