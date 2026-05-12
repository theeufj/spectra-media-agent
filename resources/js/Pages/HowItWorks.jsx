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
                <script type="application/ld+json">{JSON.stringify({
                    "@context": "https://schema.org",
                    "@type": "HowTo",
                    "name": "How to Launch AI-Powered Ad Campaigns with sitetospend",
                    "description": "From URL to ROI in 3 simple steps: Vision AI brand extraction, competitive intelligence, and autonomous campaign optimization.",
                    "step": [
                        {
                            "@type": "HowToStep",
                            "position": 1,
                            "name": "Vision AI Brand Extraction",
                            "text": "Enter your website URL. Our Crawler takes a high-resolution screenshot, and Gemini Vision AI instantly extracts your hex codes, fonts, and brand voice.",
                            "url": "https://sitetospend.com/how-it-works"
                        },
                        {
                            "@type": "HowToStep",
                            "position": 2,
                            "name": "Competitive Intelligence",
                            "text": "Our Competitor Discovery Agent uses Google Search to find your real competitors. The Analysis Agent scrapes their sites, extracts their messaging, and generates counter-strategies.",
                            "url": "https://sitetospend.com/how-it-works"
                        },
                        {
                            "@type": "HowToStep",
                            "position": 3,
                            "name": "Autonomous Optimization",
                            "text": "Deploy with one click. Self-Optimising Agents fix disapproved ads automatically. Budget Intelligence shifts spend to peak hours. Creative Testing identifies winners and generates new variations—all autonomously, 24/7.",
                            "url": "https://sitetospend.com/how-it-works"
                        }
                    ]
                })}</script>
            </Head>
            <div className="min-h-screen bg-gray-50 text-gray-800">
                <Header auth={auth} />

                <main>
                    {/* Hero */}
                    <div className="bg-gradient-to-b from-flame-orange-50 to-white py-16 sm:py-24">
                        <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 text-center">
                            <p className="text-sm font-semibold text-flame-orange-600 uppercase tracking-wider">How It Works</p>
                            <h1 className="mt-3 text-4xl sm:text-5xl font-extrabold tracking-tight text-gray-900">
                                Up and running in 3 steps
                            </h1>
                            <p className="mt-6 max-w-2xl mx-auto text-lg text-gray-500">
                                Just enter your website address. We handle everything else.
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
                                    <h2 className="text-3xl font-bold text-gray-900 mb-4">1. We read your website</h2>
                                    <p className="text-lg text-gray-600 leading-relaxed mb-6">
                                        Enter your website address and we take it from there. We read your site and pick up your colours, fonts, and the way you talk about your business — so your ads sound like you from day one.
                                    </p>
                                    <ul className="space-y-3">
                                        {['Your colours and visual style, read automatically', 'Your fonts and brand feel', 'Your tone of voice and messaging', 'Your products and services'].map((item) => (
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
                                        <p className="mt-4 text-flame-orange-600 font-semibold">Your brand, understood instantly</p>
                                        <p className="text-sm text-gray-500 mt-2">Just your website address. That's all we need.</p>
                                    </div>
                                </div>
                            </div>

                            {/* Step 2 */}
                            <div className="grid grid-cols-1 lg:grid-cols-2 gap-12 items-center mb-24">
                                <div className="order-2 lg:order-1 bg-flame-orange-50 rounded-2xl p-8 flex items-center justify-center min-h-[300px]">
                                    <div className="text-center">
                                        <span className="text-7xl">🧠</span>
                                        <p className="mt-4 text-flame-orange-600 font-semibold">Know your competition</p>
                                        <p className="text-sm text-gray-500 mt-2">Find them → read them → beat them</p>
                                    </div>
                                </div>
                                <div className="order-1 lg:order-2">
                                    <div className="inline-flex items-center justify-center h-14 w-14 rounded-full bg-flame-orange-100 mb-6">
                                        <span className="text-3xl">🧠</span>
                                    </div>
                                    <h2 className="text-3xl font-bold text-gray-900 mb-4">2. We find your competitors</h2>
                                    <p className="text-lg text-gray-600 leading-relaxed mb-6">
                                        We look at who else is advertising in your space, read through their websites, and work out the best angles for your ads to stand out. Updated every week without you having to ask.
                                    </p>
                                    <ul className="space-y-3">
                                        {['Finds competitors you might not know about', 'Reads their websites and messaging', 'Checks their pricing and offers', 'Tells you exactly how to stand out from them'].map((item) => (
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
                                    <h2 className="text-3xl font-bold text-gray-900 mb-4">3. Your ads run themselves</h2>
                                    <p className="text-lg text-gray-600 leading-relaxed mb-6">
                                        Launch with one click. From then on, rejected ads get fixed, budget moves to where it's performing, and we're always testing new ideas to improve your results. Every day, automatically.
                                    </p>
                                    <ul className="space-y-3">
                                        {['Rejected ads fixed and resubmitted automatically', 'Budget shifts to your best-performing hours every day', 'Continuously testing headline variations to find winners', 'Refreshes lookalike audiences from your customers every week'].map((item) => (
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
                                        <p className="mt-4 text-flame-orange-600 font-semibold">Improving every day</p>
                                        <p className="text-sm text-gray-500 mt-2">Launch → Learn → Keep getting better</p>
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
                                    { quote: "As an event platform, we need to reach the right audience fast. sitetospend found competitors we didn't even know about and built campaigns that outperformed our old agency from day one. The self-optimising ads alone saved us thousands.", name: 'Jamie L.', role: 'Founder', company: 'PapSnap', url: 'https://papsnap.com' },
                                    { quote: "Running an e-commerce store builder means I don't have time to babysit ad campaigns. sitetospend's AI agents do it all—budget optimization, creative testing, audience targeting. The results have been incredible for a fraction of what we were paying our agency.", name: 'Mike R.', role: 'Co-Founder', company: 'YourFirstStore', url: 'https://yourfirststore.com' },
                                    { quote: "Managing marketing for a golf marketplace with venues, coaches, and members is complex. sitetospend's AI agents handle the nuance beautifully—different campaigns for different audiences, all optimized automatically. It's like having a full marketing team on autopilot.", name: 'Alicia M.', role: 'Founder', company: 'Zonely', url: 'https://zonely.co' },
                                    { quote: "We've been in digital marketing for 20+ years and sitetospend is genuinely impressive. We use it for clients who need always-on campaign optimization. The budget shifting and creative testing features deliver results that rival hands-on management—at a fraction of the effort.", name: 'Daniel K.', role: 'Director', company: 'First Digital', url: 'https://firstdigital.co.nz' },
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
                                Sign up free and see your first ads ready to launch in minutes.
                            </p>
                            <div className="mt-10 flex flex-col sm:flex-row gap-4 justify-center">
                                <a href="/register" className="inline-flex items-center justify-center px-8 py-4 border border-transparent text-lg font-medium rounded-lg text-flame-orange-600 bg-white hover:bg-gray-50 shadow-lg">
                                    Start Free Trial
                                </a>
                                <a href="/pricing" className="inline-flex items-center justify-center px-8 py-4 border-2 border-white text-lg font-medium rounded-lg text-white hover:bg-flame-orange-700">
                                    View Pricing
                                </a>
                            </div>
                            <p className="mt-8 text-flame-orange-100">✓ No credit card required · ✓ Free to explore · ✓ Live in minutes</p>
                        </div>
                    </div>
                </main>

                <Footer />
            </div>
        </>
    );
}
