import React from 'react';
import { Head, Link } from '@inertiajs/react';
import Header from '@/Components/Header';
import Footer from '@/Components/Footer';

export default function HowItWorks({ auth }) {
    return (
        <>
            <Head>
                <title>How It Works - From URL to ROI in 3 Steps | sitetospend</title>
                <meta name="description" content="Learn how sitetospend works: Vision AI extracts your brand, competitive intelligence discovers your rivals, and autonomous agents optimize your campaigns 24/7." />
                <meta property="og:title" content="How It Works — From URL to ROI in 3 Steps | sitetospend" />
                <meta property="og:description" content="Enter your URL, let AI extract your brand, discover competitors, and deploy optimized campaigns in minutes." />
                <meta name="twitter:title" content="How It Works — From URL to ROI in 3 Steps | sitetospend" />
                <meta name="twitter:description" content="Enter your URL, let AI extract your brand, discover competitors, and deploy optimized campaigns in minutes." />
            </Head>
            <div className="min-h-screen bg-gray-50 text-gray-800">
                <Header auth={auth} />

                <main>
                    {/* Hero */}
                    <div className="bg-gradient-to-b from-flame-orange-50 to-white py-16 sm:py-24">
                        <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 text-center">
                            <p className="text-sm font-semibold text-flame-orange-600 uppercase tracking-wider">How It Works</p>
                            <h1 className="mt-3 text-4xl sm:text-5xl font-extrabold tracking-tight text-gray-900">
                                From URL to ROI in 3 Simple Steps
                            </h1>
                            <p className="mt-6 max-w-2xl mx-auto text-lg text-gray-500">
                                Our autonomous agents handle the complex workflow so you don't have to. Just enter your URL and we do the rest.
                            </p>
                        </div>
                    </div>

                    {/* 3 Steps - Expanded */}
                    <div className="bg-white py-16 sm:py-24">
                        <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                            {/* Step 1 */}
                            <div className="grid grid-cols-1 lg:grid-cols-2 gap-12 items-center mb-24">
                                <div>
                                    <div className="inline-flex items-center justify-center h-14 w-14 rounded-full bg-flame-orange-100 mb-6">
                                        <span className="text-3xl">👁️</span>
                                    </div>
                                    <h2 className="text-3xl font-bold text-gray-900 mb-4">1. Vision AI Brand Extraction</h2>
                                    <p className="text-lg text-gray-600 leading-relaxed mb-6">
                                        Enter your website URL. Our Crawler takes a high-resolution screenshot, and Gemini Vision AI instantly extracts your hex codes, fonts, and brand voice. No manual setup, no brand guidelines PDF—just instant understanding.
                                    </p>
                                    <ul className="space-y-3">
                                        {['Automatic color palette extraction', 'Font & typography detection', 'Brand voice & tone analysis', 'Logo and visual style recognition'].map((item) => (
                                            <li key={item} className="flex items-center text-gray-600">
                                                <svg className="h-5 w-5 text-flame-orange-500 mr-3" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M5 13l4 4L19 7" /></svg>
                                                {item}
                                            </li>
                                        ))}
                                    </ul>
                                </div>
                                <div className="bg-flame-orange-50 rounded-2xl p-8 flex items-center justify-center min-h-[300px]">
                                    <div className="text-center">
                                        <span className="text-7xl">👁️</span>
                                        <p className="mt-4 text-flame-orange-600 font-semibold">Vision AI Extraction</p>
                                        <p className="text-sm text-gray-500 mt-2">URL → Brand Identity in seconds</p>
                                    </div>
                                </div>
                            </div>

                            {/* Step 2 */}
                            <div className="grid grid-cols-1 lg:grid-cols-2 gap-12 items-center mb-24">
                                <div className="order-2 lg:order-1 bg-flame-orange-50 rounded-2xl p-8 flex items-center justify-center min-h-[300px]">
                                    <div className="text-center">
                                        <span className="text-7xl">🧠</span>
                                        <p className="mt-4 text-flame-orange-600 font-semibold">Competitive Intelligence</p>
                                        <p className="text-sm text-gray-500 mt-2">Discover → Analyze → Counter-Strategy</p>
                                    </div>
                                </div>
                                <div className="order-1 lg:order-2">
                                    <div className="inline-flex items-center justify-center h-14 w-14 rounded-full bg-flame-orange-100 mb-6">
                                        <span className="text-3xl">🧠</span>
                                    </div>
                                    <h2 className="text-3xl font-bold text-gray-900 mb-4">2. Competitive Intelligence</h2>
                                    <p className="text-lg text-gray-600 leading-relaxed mb-6">
                                        Our Competitor Discovery Agent uses Google Search to find your real competitors. The Analysis Agent scrapes their sites, extracts their messaging, and generates counter-strategies to help you win.
                                    </p>
                                    <ul className="space-y-3">
                                        {['Automatic competitor identification via Google Search', 'Website scraping & messaging extraction', 'Value proposition & pricing analysis', 'AI-generated counter-strategy recommendations'].map((item) => (
                                            <li key={item} className="flex items-center text-gray-600">
                                                <svg className="h-5 w-5 text-flame-orange-500 mr-3" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M5 13l4 4L19 7" /></svg>
                                                {item}
                                            </li>
                                        ))}
                                    </ul>
                                </div>
                            </div>

                            {/* Step 3 */}
                            <div className="grid grid-cols-1 lg:grid-cols-2 gap-12 items-center">
                                <div>
                                    <div className="inline-flex items-center justify-center h-14 w-14 rounded-full bg-flame-orange-100 mb-6">
                                        <span className="text-3xl">🚀</span>
                                    </div>
                                    <h2 className="text-3xl font-bold text-gray-900 mb-4">3. Autonomous Optimization</h2>
                                    <p className="text-lg text-gray-600 leading-relaxed mb-6">
                                        Deploy with one click. Self-Healing Agents fix disapproved ads automatically. Budget Intelligence shifts spend to peak hours. Creative Testing identifies winners and generates new variations—all autonomously, 24/7.
                                    </p>
                                    <ul className="space-y-3">
                                        {['Self-healing for disapproved ads', 'Hourly budget optimization by time-of-day', 'Automated A/B testing & creative generation', 'Audience segmentation & expansion'].map((item) => (
                                            <li key={item} className="flex items-center text-gray-600">
                                                <svg className="h-5 w-5 text-flame-orange-500 mr-3" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M5 13l4 4L19 7" /></svg>
                                                {item}
                                            </li>
                                        ))}
                                    </ul>
                                </div>
                                <div className="bg-flame-orange-50 rounded-2xl p-8 flex items-center justify-center min-h-[300px]">
                                    <div className="text-center">
                                        <span className="text-7xl">🚀</span>
                                        <p className="mt-4 text-flame-orange-600 font-semibold">Autonomous Optimization</p>
                                        <p className="text-sm text-gray-500 mt-2">Deploy → Optimize → Scale</p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    {/* Testimonials */}
                    <div className="bg-gray-50 py-16 sm:py-24">
                        <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                            <div className="mx-auto max-w-2xl lg:text-center mb-16">
                                <h2 className="text-3xl font-bold tracking-tight text-gray-900 sm:text-4xl">From Our Customers</h2>
                                <p className="mt-4 text-lg text-gray-600">See how businesses like yours are succeeding with sitetospend.</p>
                            </div>
                            <div className="grid max-w-2xl grid-cols-1 gap-8 sm:grid-cols-2 xl:max-w-none xl:grid-cols-3">
                                {[
                                    { quote: "sitetospend completely transformed how we run ads for our security platform. The AI agents handle our Google Ads around the clock—competitor targeting, budget shifts, creative testing—all automated. We've cut our ad management time by 80%.", name: 'Josh T.', role: 'Founder', company: 'Proveably', url: 'https://proveably.com' },
                                    { quote: "As an event platform, we need to reach the right audience fast. sitetospend's autonomous agents discovered competitors we didn't even know about and built campaigns that outperformed our old agency from day one. The self-healing ads alone saved us thousands.", name: 'Jamie L.', role: 'Founder', company: 'PapSnap', url: 'https://papsnap.com' },
                                    { quote: "Running an e-commerce store builder means I don't have time to babysit ad campaigns. sitetospend's AI agents do it all—budget optimization, creative testing, audience targeting. The results have been incredible for a fraction of what we were paying our agency.", name: 'Mike R.', role: 'Co-Founder', company: 'YourFirstStore', url: 'https://yourfirststore.com' },
                                    { quote: "Managing marketing for a golf marketplace with venues, coaches, and members is complex. sitetospend's AI agents handle the nuance beautifully—different campaigns for different audiences, all optimized automatically. It's like having a full marketing team on autopilot.", name: 'Alicia M.', role: 'Founder', company: 'Zonely', url: 'https://zonely.co' },
                                    { quote: "We've been in digital marketing for 20+ years and sitetospend is genuinely impressive. We use it for clients who need always-on campaign optimization. The autonomous budget intelligence and creative testing agents deliver results that rival manual management at scale.", name: 'Daniel K.', role: 'Director', company: 'First Digital', url: 'https://firstdigital.co.nz' },
                                ].map((testimonial) => (
                                    <div key={testimonial.name} className="relative rounded-2xl bg-white p-6 shadow-sm ring-1 ring-gray-900/5 hover:shadow-md transition-shadow">
                                        <div className="flex gap-1 mb-4">
                                            {[...Array(5)].map((_, i) => <span key={i} className="text-yellow-400">★</span>)}
                                        </div>
                                        <p className="text-lg font-medium text-gray-900">"{testimonial.quote}"</p>
                                        <div className="mt-6 font-semibold">{testimonial.name}</div>
                                        <div className="text-sm text-gray-600">{testimonial.role}, <a href={testimonial.url} target="_blank" rel="noopener noreferrer" className="text-flame-orange-600 hover:text-flame-orange-700">{testimonial.company}</a></div>
                                    </div>
                                ))}
                            </div>
                        </div>
                    </div>

                    {/* CTA */}
                    <div className="bg-gradient-to-r from-flame-orange-600 to-flame-orange-800 py-16 sm:py-24">
                        <div className="max-w-4xl mx-auto text-center px-4 sm:px-6 lg:px-8">
                            <h2 className="text-4xl font-extrabold text-white sm:text-5xl">
                                Ready to get started?
                            </h2>
                            <p className="mt-6 text-xl text-flame-orange-100">
                                Sign up free and let our AI agents transform your marketing in minutes.
                            </p>
                            <div className="mt-10 flex flex-col sm:flex-row gap-4 justify-center">
                                <a href="/register" className="inline-flex items-center justify-center px-8 py-4 border border-transparent text-lg font-medium rounded-lg text-flame-orange-600 bg-white hover:bg-gray-50 shadow-lg">
                                    Start Free Trial
                                </a>
                                <a href="/pricing" className="inline-flex items-center justify-center px-8 py-4 border-2 border-white text-lg font-medium rounded-lg text-white hover:bg-flame-orange-700">
                                    View Pricing
                                </a>
                            </div>
                            <p className="mt-8 text-flame-orange-100">✓ No credit card required · ✓ Generous free tier · ✓ Deploy in minutes</p>
                        </div>
                    </div>
                </main>

                <Footer />
            </div>
        </>
    );
}
