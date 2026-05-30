import React from 'react';
import { Head, Link } from '@inertiajs/react';
import Header from '@/Components/Header';
import Footer from '@/Components/Footer';

const features = [
    {
        icon: (
            <svg className="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.5} d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6" />
            </svg>
        ),
        title: 'Property Listing Ads',
        description: 'Automatically generate Google Search and Display ads for each listing — tailored to the property features, price point, and neighbourhood.',
    },
    {
        icon: (
            <svg className="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.5} d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z" />
            </svg>
        ),
        title: 'Seller Lead Generation',
        description: 'Target homeowners who are likely to sell with "Sell My House" campaigns, retargeting ads, and lookalike audiences built from your existing clients.',
    },
    {
        icon: (
            <svg className="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.5} d="M9 20l-5.447-2.724A1 1 0 013 16.382V5.618a1 1 0 011.447-.894L9 7m0 13l6-3m-6 3V7m6 10l4.553 2.276A1 1 0 0021 18.382V7.618a1 1 0 00-.553-.894L15 4m0 13V4m0 0L9 7" />
            </svg>
        ),
        title: 'Hyper-Local Targeting',
        description: 'Reach buyers in the specific suburbs, postcodes, and school districts your listings are in — not just broad metro areas.',
    },
    {
        icon: (
            <svg className="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.5} d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6" />
            </svg>
        ),
        title: 'Automated Optimisation',
        description: 'AI agents monitor your campaigns around the clock — pausing underperformers, boosting winning ads, and reallocating budget to what\'s converting.',
    },
    {
        icon: (
            <svg className="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.5} d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
            </svg>
        ),
        title: 'Weekly Performance Reports',
        description: 'Clear, branded reports delivered to your inbox every week — impressions, clicks, leads, cost per lead. No spreadsheets, no guesswork.',
    },
    {
        icon: (
            <svg className="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.5} d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
            </svg>
        ),
        title: 'Launch in Minutes',
        description: 'Connect your Google Ads account, add your first listing URL, and your first campaign is live — no agency, no setup fees, no learning curve.',
    },
];

const steps = [
    {
        number: '01',
        title: 'Connect Your Accounts',
        description: 'Link Google Ads in one click. Our AI reads your agency website to understand your brand voice, style, and market.',
    },
    {
        number: '02',
        title: 'Add Your Listings',
        description: 'Paste the URL of a property you want to promote. The AI scans the listing and writes ad copy, picks keywords, and sets your targeting automatically.',
    },
    {
        number: '03',
        title: 'Launch & Let the AI Work',
        description: 'Your campaigns go live. The AI monitors performance, adjusts bids, and optimises daily — while you focus on clients and closings.',
    },
];

export default function RealEstateLanding({ auth }) {
    return (
        <>
            <Head>
                <title>Real Property Ads — Ad Campaigns Built for Real Estate Agents</title>
                <meta name="description" content="Get more listings, sell faster. AI-powered Google Ads built specifically for real estate agents. Launch your first property campaign in minutes." />
                <meta property="og:title" content="Real Property Ads — More Listings. More Closings." />
                <meta property="og:description" content="Stop paying for generic ads. Real Property Ads generates Google campaigns tailored to each property listing automatically." />
                <meta property="og:type" content="website" />
            </Head>

            <Header auth={auth} />

            {/* Hero */}
            <section className="bg-white">
                <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-20 lg:py-28">
                    <div className="text-center max-w-3xl mx-auto">
                        <div className="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-brand-primary/10 text-brand-primary text-sm font-medium mb-6">
                            <span className="w-1.5 h-1.5 rounded-full bg-brand-primary"></span>
                            Built exclusively for real estate agents
                        </div>
                        <h1 className="text-4xl sm:text-5xl lg:text-6xl font-bold text-gray-900 leading-tight">
                            Get more listings.
                            <br />
                            <span className="text-brand-primary">Sell faster.</span>
                        </h1>
                        <p className="mt-6 text-xl text-gray-600 leading-relaxed">
                            AI-powered Google Ads that write themselves around your listings — targeting the right buyers and sellers in your market, 24/7.
                        </p>
                        <div className="mt-10 flex flex-col sm:flex-row items-center justify-center gap-4">
                            <a
                                href="/register"
                                className="inline-flex items-center justify-center px-8 py-4 rounded-lg text-lg font-semibold text-white bg-brand-primary hover:bg-brand-dark transition-colors shadow-lg"
                            >
                                Start your first property campaign
                                <svg className="ml-2 w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M17 8l4 4m0 0l-4 4m4-4H3" />
                                </svg>
                            </a>
                            <a href="/pricing" className="text-gray-600 hover:text-gray-900 font-medium">
                                View pricing →
                            </a>
                        </div>
                        <p className="mt-4 text-sm text-gray-400">No credit card required. Set up in under 5 minutes.</p>
                    </div>
                </div>
            </section>

            {/* Stats strip */}
            <section className="bg-brand-primary text-white">
                <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-10">
                    <div className="grid grid-cols-1 sm:grid-cols-3 gap-8 text-center">
                        <div>
                            <p className="text-4xl font-bold">3×</p>
                            <p className="mt-1 text-brand-accent font-medium">more leads per dollar</p>
                            <p className="text-sm text-white/70 mt-1">vs. running ads manually</p>
                        </div>
                        <div>
                            <p className="text-4xl font-bold">&lt; 5 min</p>
                            <p className="mt-1 text-brand-accent font-medium">to launch a campaign</p>
                            <p className="text-sm text-white/70 mt-1">from listing URL to live ads</p>
                        </div>
                        <div>
                            <p className="text-4xl font-bold">24/7</p>
                            <p className="mt-1 text-brand-accent font-medium">AI optimisation</p>
                            <p className="text-sm text-white/70 mt-1">while you focus on clients</p>
                        </div>
                    </div>
                </div>
            </section>

            {/* Features */}
            <section className="bg-gray-50 py-20">
                <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
                    <div className="text-center mb-14">
                        <h2 className="text-3xl sm:text-4xl font-bold text-gray-900">Everything a top-producing agent needs</h2>
                        <p className="mt-4 text-lg text-gray-600 max-w-2xl mx-auto">
                            Purpose-built for real estate. Not a generic marketing tool with a real estate checkbox.
                        </p>
                    </div>
                    <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-8">
                        {features.map((feature) => (
                            <div key={feature.title} className="bg-white rounded-xl p-6 shadow-sm border border-gray-100">
                                <div className="w-10 h-10 rounded-lg bg-brand-primary/10 flex items-center justify-center text-brand-primary mb-4">
                                    {feature.icon}
                                </div>
                                <h3 className="text-lg font-semibold text-gray-900 mb-2">{feature.title}</h3>
                                <p className="text-gray-600 text-sm leading-relaxed">{feature.description}</p>
                            </div>
                        ))}
                    </div>
                </div>
            </section>

            {/* How it works */}
            <section className="bg-white py-20">
                <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
                    <div className="text-center mb-14">
                        <h2 className="text-3xl sm:text-4xl font-bold text-gray-900">Up and running in three steps</h2>
                    </div>
                    <div className="grid grid-cols-1 md:grid-cols-3 gap-10">
                        {steps.map((step) => (
                            <div key={step.number} className="relative">
                                <div className="text-5xl font-bold text-brand-primary/20 mb-4">{step.number}</div>
                                <h3 className="text-xl font-semibold text-gray-900 mb-3">{step.title}</h3>
                                <p className="text-gray-600 leading-relaxed">{step.description}</p>
                            </div>
                        ))}
                    </div>
                </div>
            </section>

            {/* CTA */}
            <section className="bg-brand-primary py-20">
                <div className="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8 text-center">
                    <h2 className="text-3xl sm:text-4xl font-bold text-white">Ready to fill your pipeline?</h2>
                    <p className="mt-4 text-lg text-white/80">
                        Join real estate agents who are using AI to win more listings and sell faster — without hiring a marketing agency.
                    </p>
                    <div className="mt-8 flex flex-col sm:flex-row items-center justify-center gap-4">
                        <a
                            href="/register"
                            className="inline-flex items-center justify-center px-8 py-4 rounded-lg text-lg font-semibold text-brand-primary bg-white hover:bg-gray-50 transition-colors shadow-lg"
                        >
                            Start free — no credit card needed
                        </a>
                        <a href="/login" className="text-white/80 hover:text-white font-medium">
                            Already have an account? Log in →
                        </a>
                    </div>
                </div>
            </section>

            <Footer />
        </>
    );
}
