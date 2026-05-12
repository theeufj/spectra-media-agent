import React from 'react';
import { Head, Link } from '@inertiajs/react';
import Header from '@/Components/Header';
import Footer from '@/Components/Footer';

export default function About({ auth }) {
    return (
        <>
            <Head>
                <title>About Us - AI-Powered Marketing for Everyone | sitetospend</title>
                <meta name="description" content="sitetospend is on a mission to democratize digital advertising. Our AI agents deliver agency-level results at a fraction of the cost—so every business can compete." />
                <meta property="og:title" content="About sitetospend — Democratizing Digital Advertising with AI" />
                <meta property="og:description" content="Our mission: agency-level marketing results at a fraction of the cost, powered by autonomous AI agents." />
                <meta name="twitter:title" content="About sitetospend — Democratizing Digital Advertising with AI" />
                <meta name="twitter:description" content="Our mission: agency-level marketing results at a fraction of the cost, powered by autonomous AI agents." />
                <script type="application/ld+json">{JSON.stringify({
                    "@context": "https://schema.org",
                    "@type": "AboutPage",
                    "name": "About sitetospend",
                    "description": "sitetospend is on a mission to democratize digital advertising with autonomous AI agents.",
                    "url": "https://sitetospend.com/about",
                    "mainEntity": {
                        "@type": "Organization",
                        "name": "sitetospend",
                        "url": "https://sitetospend.com",
                        "logo": "https://sitetospend.com/og-image.png",
                        "description": "AI-powered digital advertising platform with 6 autonomous agents that manage and optimize ad campaigns across Google, Facebook, and Microsoft.",
                        "foundingDate": "2026",
                        "knowsAbout": ["Digital Advertising", "AI Marketing", "Google Ads", "Facebook Ads", "Campaign Optimization"]
                    }
                })}</script>
            </Head>
            <div className="min-h-screen bg-gray-50 text-gray-800">
                <Header auth={auth} />

                <main>
                    {/* Hero */}
                    <div className="bg-gradient-to-b from-flame-orange-50 to-white py-16 sm:py-24">
                        <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 text-center">
                            <p className="text-sm font-semibold text-flame-orange-600 uppercase tracking-wider">About Us</p>
                            <h1 className="mt-3 text-4xl sm:text-5xl font-extrabold tracking-tight text-gray-900">
                                Agency-Level Marketing, Accessible&nbsp;to&nbsp;Everyone
                            </h1>
                            <p className="mt-6 max-w-2xl mx-auto text-lg text-gray-500">
                                We believe every business—from local shops to scaling startups—deserves the same caliber of digital advertising that Fortune 500 companies get.
                            </p>
                        </div>
                    </div>

                    {/* Mission */}
                    <div className="bg-white py-16 sm:py-24">
                        <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                            <div className="grid grid-cols-1 lg:grid-cols-2 gap-16 items-center">
                                <div>
                                    <h2 className="text-3xl font-bold text-gray-900 mb-6">Our Mission</h2>
                                    <p className="text-lg text-gray-600 leading-relaxed mb-6">
                                        Traditional agencies charge thousands a month, lock you into long contracts, and send you a report once a week if you're lucky. We built sitetospend because that's just not good enough for most businesses.
                                    </p>
                                    <p className="text-lg text-gray-600 leading-relaxed mb-6">
                                        Our AI works every hour of every day—finding your competitors, shifting your budget to the right times, fixing rejected ads the moment they happen, and always testing new ideas. It never takes a day off, never misses something, and costs a fraction of what an agency charges.
                                    </p>
                                    <p className="text-lg text-gray-600 leading-relaxed">
                                        We're not here to replace your marketing instincts—we're here to give every business the tools that used to be reserved for companies with enormous ad budgets.
                                    </p>
                                </div>
                                <div className="bg-flame-orange-50 rounded-2xl p-10">
                                    <div className="space-y-8">
                                        {[
                                            { stat: '24/7', label: 'Campaign Monitoring' },
                                            { stat: '6', label: 'Autonomous AI Agents' },
                                            { stat: '<5 min', label: 'Setup to First Campaign' },
                                            { stat: '96%', label: 'Cost Savings vs. Agencies' },
                                        ].map((item) => (
                                            <div key={item.label} className="flex items-center gap-4">
                                                <div className="text-3xl font-extrabold text-flame-orange-600 w-24 text-right">{item.stat}</div>
                                                <div className="text-gray-700 font-medium">{item.label}</div>
                                            </div>
                                        ))}
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    {/* Values */}
                    <div className="bg-gray-50 py-16 sm:py-24">
                        <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                            <div className="text-center mb-16">
                                <h2 className="text-3xl font-bold text-gray-900">What We Stand For</h2>
                            </div>
                            <div className="grid grid-cols-1 md:grid-cols-3 gap-8">
                                {[
                                    { icon: '🔍', title: 'Transparency', desc: 'No hidden fees, no markup on ad spend. You pay the platforms directly and always see exactly where your money goes.' },
                                    { icon: '🤖', title: 'Always On', desc: "The best marketing never sleeps. Our AI doesn't rest—it's fixing issues, testing new ideas, and shifting your budget while you're focused on everything else." },
                                    { icon: '🛡️', title: 'Your Data, Your Control', desc: 'Your customer data and brand assets stay yours. We create and manage your ad accounts under our management umbrella—you always retain full ownership and visibility.' },
                                ].map((value) => (
                                    <div key={value.title} className="bg-white rounded-xl p-8 shadow-sm ring-1 ring-gray-900/5">
                                        <span className="text-4xl">{value.icon}</span>
                                        <h3 className="mt-4 text-xl font-bold text-gray-900">{value.title}</h3>
                                        <p className="mt-3 text-gray-600 leading-relaxed">{value.desc}</p>
                                    </div>
                                ))}
                            </div>
                        </div>
                    </div>

                    {/* How We're Different */}
                    <div className="bg-white py-16 sm:py-24">
                        <div className="mx-auto max-w-4xl px-4 sm:px-6 lg:px-8">
                            <div className="text-center mb-12">
                                <h2 className="text-3xl font-bold text-gray-900">How We're Different</h2>
                            </div>
                            <div className="space-y-6">
                                {[
                                    { label: 'Your brand, instantly', desc: 'No 20-page brand questionnaires. We read your website and understand your colours, fonts, and tone straight away. Every ad looks like it came from your own team.' },
                                    { label: 'Problems get fixed before you notice', desc: 'Rejected ad? Fixed. Underperforming creative? Paused and replaced. We don\'t wait for a Monday morning call to deal with it.' },
                                    { label: 'We watch your competitors for you', desc: 'Every week we look at who\'s advertising in your space, read their websites, and figure out how you can beat them. You just see the results.' },
                                    { label: 'What you see is what you pay', desc: 'Your subscription covers the platform. Your ad spend goes straight to Google, Facebook, and the others. We never add a margin to your media costs.' },
                                ].map((item) => (
                                    <div key={item.label} className="flex gap-4 items-start">
                                        <div className="flex-shrink-0 w-2 h-2 mt-2 rounded-full bg-flame-orange-500"></div>
                                        <div>
                                            <h3 className="font-bold text-gray-900">{item.label}</h3>
                                            <p className="text-gray-600">{item.desc}</p>
                                        </div>
                                    </div>
                                ))}
                            </div>
                        </div>
                    </div>

                    {/* CTA */}
                    <div className="bg-gradient-to-r from-flame-orange-600 to-flame-orange-800 py-16 sm:py-24">
                        <div className="max-w-4xl mx-auto text-center px-4 sm:px-6 lg:px-8">
                            <h2 className="text-4xl font-extrabold text-white sm:text-5xl">
                                Ready to see the difference?
                            </h2>
                            <p className="mt-6 text-xl text-flame-orange-100">
                                Start free and discover what AI-powered marketing can do for your business.
                            </p>
                            <div className="mt-10 flex flex-col sm:flex-row gap-4 justify-center">
                                <a href="/register" className="inline-flex items-center justify-center px-8 py-4 border border-transparent text-lg font-medium rounded-lg text-flame-orange-600 bg-white hover:bg-gray-50 shadow-lg">
                                    Get Started Free
                                </a>
                                <Link href="/features" className="inline-flex items-center justify-center px-8 py-4 border-2 border-white text-lg font-medium rounded-lg text-white hover:bg-flame-orange-700">
                                    Explore Features
                                </Link>
                            </div>
                            <p className="mt-8 text-flame-orange-100">✓ No credit card required · ✓ Free to explore · ✓ Cancel anytime</p>
                        </div>
                    </div>
                </main>

                <Footer />
            </div>
        </>
    );
}
