import React from 'react';
import { Head } from '@inertiajs/react';
import Header from '@/Components/Header';
import Footer from '@/Components/Footer';

export default function Landing({ auth }) {
    const [openFAQ, setOpenFAQ] = React.useState(null);
    return (
        <>
            <Head title="cvseeyou - AI-Powered Digital Marketing" />
            <div className="min-h-screen bg-gray-50 text-gray-800">
                <Header auth={auth} />

                <main>
                    {/* Hero Section - Enhanced */}
                    <div className="pt-10 bg-gradient-to-b from-indigo-50 to-white sm:pt-16 lg:pt-8 lg:pb-14 lg:overflow-hidden">
                        <div className="mx-auto max-w-7xl lg:px-8">
                            <div className="lg:grid lg:grid-cols-2 lg:gap-8">
                                <div className="mx-auto max-w-md px-4 sm:max-w-2xl sm:px-6 sm:text-center lg:px-0 lg:text-left lg:flex lg:items-center">
                                    <div className="lg:py-24">
                                        <p className="text-sm font-semibold text-indigo-600 uppercase tracking-wider">Join 500+ Marketing Teams</p>
                                        <h1 className="mt-4 text-4xl tracking-tight font-extrabold text-gray-900 sm:mt-5 sm:text-6xl lg:mt-6 xl:text-6xl">
                                            <span className="block">Agentic Digital Marketing</span>
                                            <span className="block text-indigo-600">Powered by AI</span>
                                        </h1>
                                        <p className="mt-3 text-base text-gray-500 sm:mt-5 sm:text-xl lg:text-lg xl:text-xl">
                                            Generate ad copy, images, and videos for free. When you're ready to launch, our AI agents will deploy and manage your campaigns for a flat monthly fee. Stop paying thousands for ad management.
                                        </p>
                                        <div className="mt-10 sm:mt-12 flex flex-col sm:flex-row gap-4">
                                            <a
                                                href="/register"
                                                className="inline-flex items-center justify-center px-6 py-3 border border-transparent text-base font-medium rounded-md text-white bg-indigo-600 hover:bg-indigo-700 shadow-lg"
                                            >
                                                Start Generating for Free
                                            </a>
                                            <a
                                                href="#how-it-works"
                                                className="inline-flex items-center justify-center px-6 py-3 border-2 border-indigo-600 text-base font-medium rounded-md text-indigo-600 bg-white hover:bg-indigo-50"
                                            >
                                                See How It Works
                                            </a>
                                        </div>
                                        <p className="mt-6 text-sm text-gray-500">✓ No credit card required · ✓ Free forever tier · ✓ Cancel anytime</p>
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
                    <div id="how-it-works" className="bg-white py-16 sm:py-24">
                        <div className="mx-auto max-w-7xl px-6 lg:px-8">
                            <div className="mx-auto max-w-2xl lg:text-center mb-16">
                                <h2 className="text-3xl font-bold tracking-tight text-gray-900 sm:text-4xl">How It Works</h2>
                                <p className="mt-6 text-lg leading-8 text-gray-600">
                                    Three simple steps to launch your first campaign
                                </p>
                            </div>
                            <div className="grid grid-cols-1 md:grid-cols-3 gap-8">
                                <div className="relative">
                                    <div className="flex items-center justify-center h-12 w-12 rounded-md bg-indigo-500 text-white text-xl font-bold">1</div>
                                    <h3 className="mt-4 text-lg font-semibold text-gray-900">Generate Creative Assets</h3>
                                    <p className="mt-2 text-gray-600">Describe your product or service. Our AI instantly creates multiple variations of ad copy, images, and videos.</p>
                                </div>
                                <div className="relative">
                                    <div className="flex items-center justify-center h-12 w-12 rounded-md bg-indigo-500 text-white text-xl font-bold">2</div>
                                    <h3 className="mt-4 text-lg font-semibold text-gray-900">Connect Your Accounts</h3>
                                    <p className="mt-2 text-gray-600">Securely link your Google Ads, Facebook, or other advertising platform accounts to Spectra.</p>
                                </div>
                                <div className="relative">
                                    <div className="flex items-center justify-center h-12 w-12 rounded-md bg-indigo-500 text-white text-xl font-bold">3</div>
                                    <h3 className="mt-4 text-lg font-semibold text-gray-900">Deploy & Optimize</h3>
                                    <p className="mt-2 text-gray-600">Our AI agents automatically deploy campaigns, test variations, and optimize performance 24/7.</p>
                                </div>
                            </div>
                        </div>
                    </div>

                    {/* Features Section */}
                    <div className="bg-gray-50 py-16 sm:py-24">
                        <div className="mx-auto max-w-7xl px-6 lg:px-8">
                            <div className="mx-auto max-w-2xl lg:text-center">
                                <h2 className="text-base font-semibold leading-7 text-indigo-600">Deploy with Confidence</h2>
                                <p className="mt-2 text-3xl font-bold tracking-tight text-gray-900 sm:text-4xl">
                                    Everything you need to launch and optimize your ad campaigns
                                </p>
                                <p className="mt-6 text-lg leading-8 text-gray-600">
                                    Our AI agents handle the heavy lifting, from creative generation to performance analysis, so you can focus on your business.
                                </p>
                            </div>
                            <div className="mx-auto mt-16 max-w-2xl sm:mt-20 lg:mt-24 lg:max-w-4xl">
                                <dl className="grid max-w-xl grid-cols-1 gap-x-8 gap-y-10 lg:max-w-none lg:grid-cols-2 lg:gap-y-16">
                                    <div className="relative pl-16">
                                        <dt className="text-base font-semibold leading-7 text-gray-900">
                                            <div className="absolute left-0 top-0 flex h-10 w-10 items-center justify-center rounded-lg bg-indigo-600">
                                                <svg className="h-6 w-6 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M13 10V3L4 14h7v7l9-11h-7z" /></svg>
                                            </div>
                                            AI-Powered Optimization
                                        </dt>
                                        <dd className="mt-2 text-base leading-7 text-gray-600">Our agents continuously tweak and improve your ad copy, images, and video content to maximize sales and ROI.</dd>
                                    </div>
                                    <div className="relative pl-16">
                                        <dt className="text-base font-semibold leading-7 text-gray-900">
                                            <div className="absolute left-0 top-0 flex h-10 w-10 items-center justify-center rounded-lg bg-indigo-600">
                                                <svg className="h-6 w-6 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 6V4m0 2a2 2 0 100 4m0-4a2 2 0 110 4m-6 8a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4m6 6v10m6-2a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4" /></svg>
                                            </div>
                                            Multi-Platform Support
                                        </dt>
                                        <dd className="mt-2 text-base leading-7 text-gray-600">Deploy to Google Ads instantly. Facebook, Instagram, Reddit, and Microsoft support coming soon for unified marketing.</dd>
                                    </div>
                                    <div className="relative pl-16">
                                        <dt className="text-base font-semibold leading-7 text-gray-900">
                                            <div className="absolute left-0 top-0 flex h-10 w-10 items-center justify-center rounded-lg bg-indigo-600">
                                                <svg className="h-6 w-6 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                                            </div>
                                            Transparent Pricing
                                        </dt>
                                        <dd className="mt-2 text-base leading-7 text-gray-600">$200 flat fee per month plus your ad spend. No hidden costs, no surprises. Ad spend billed daily to your card.</dd>
                                    </div>
                                    <div className="relative pl-16">
                                        <dt className="text-base font-semibold leading-7 text-gray-900">
                                            <div className="absolute left-0 top-0 flex h-10 w-10 items-center justify-center rounded-lg bg-indigo-600">
                                                <svg className="h-6 w-6 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M9 19l3 3m0 0l3-3m-3 3v-6" /></svg>
                                            </div>
                                            Generate for Free
                                        </dt>
                                        <dd className="mt-2 text-base leading-7 text-gray-600">Sign up free and generate unlimited ad copy, images, and videos. Only pay when deploying campaigns.</dd>
                                    </div>
                                </dl>
                            </div>
                        </div>
                    </div>

                    {/* Case Studies Section */}
                    <div className="bg-white py-16 sm:py-24">
                        <div className="mx-auto max-w-7xl px-6 lg:px-8">
                            <div className="mx-auto max-w-2xl lg:text-center">
                                <h2 className="text-3xl font-bold tracking-tight text-gray-900 sm:text-4xl">From Our Customers</h2>
                                <p className="mt-6 text-lg leading-8 text-gray-600">
                                    See how businesses like yours are succeeding with Spectra Media Agent.
                                </p>
                            </div>
                            <div className="mx-auto mt-16 grid max-w-2xl grid-cols-1 gap-8 text-sm leading-6 text-gray-900 sm:mt-20 sm:grid-cols-2 xl:mx-0 xl:max-w-none xl:grid-flow-col xl:grid-cols-3">
                                <div className="relative rounded-2xl bg-gray-50 p-6 shadow-sm ring-1 ring-gray-900/5 hover:shadow-md transition-shadow">
                                    <div className="flex gap-1 mb-4">
                                        {[...Array(5)].map((_, i) => <span key={i} className="text-yellow-400">★</span>)}
                                    </div>
                                    <div className="text-lg font-medium">
                                        <p>"Spectra has been a game-changer for our marketing. We've seen a 30% increase in conversions since we started using their AI agents."</p>
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
                                        <p>"As a small business owner, I don't have time to manage campaigns. Spectra's AI agents do it all, and results have been fantastic."</p>
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

                            <div className="grid grid-cols-1 md:grid-cols-2 gap-8 max-w-4xl mx-auto">
                                {/* Free Tier */}
                                <div className="rounded-lg border border-gray-200 p-8 bg-white flex flex-col">
                                    <h3 className="text-2xl font-bold text-gray-900">Free</h3>
                                    <div className="mt-4 text-gray-900">
                                        <span className="text-4xl font-extrabold">$0</span>
                                        <span className="text-xl font-medium">/month</span>
                                    </div>
                                    <p className="mt-4 text-sm text-gray-500">Forever free</p>
                                    <ul className="mt-8 space-y-4 flex-grow">
                                        <li className="flex items-start">
                                            <svg className="h-6 w-6 text-green-500 mr-3 flex-shrink-0" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M5 13l4 4L19 7" />
                                            </svg>
                                            <p className="text-gray-700">Unlimited creative generation</p>
                                        </li>
                                        <li className="flex items-start">
                                            <svg className="h-6 w-6 text-green-500 mr-3 flex-shrink-0" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M5 13l4 4L19 7" />
                                            </svg>
                                            <p className="text-gray-700">Ad copy variations</p>
                                        </li>
                                        <li className="flex items-start">
                                            <svg className="h-6 w-6 text-green-500 mr-3 flex-shrink-0" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M5 13l4 4L19 7" />
                                            </svg>
                                            <p className="text-gray-700">Basic image generation</p>
                                        </li>
                                    </ul>
                                    <div className="mt-10">
                                        <a
                                            href="/register"
                                            className="block w-full text-center rounded-lg border-2 border-gray-300 px-6 py-3 text-base font-medium text-gray-900 hover:border-gray-400"
                                        >
                                            Get Started
                                        </a>
                                    </div>
                                </div>

                                {/* Pro Tier */}
                                <div className="rounded-lg border-2 border-indigo-600 p-8 bg-indigo-50 shadow-lg relative flex flex-col">
                                    <div className="absolute -top-4 left-1/2 transform -translate-x-1/2 bg-indigo-600 text-white px-3 py-1 text-xs font-semibold rounded-full">
                                        Most Popular
                                    </div>
                                    <h3 className="text-2xl font-bold text-gray-900">Pro Plan</h3>
                                    <div className="mt-4 text-gray-900">
                                        <span className="text-5xl font-extrabold">$200</span>
                                        <span className="text-xl font-medium">/month</span>
                                    </div>
                                    <p className="mt-4 text-sm text-gray-600">+ ad spend, billed daily.</p>
                                    <ul className="mt-8 space-y-4 flex-grow">
                                        <li className="flex items-start">
                                            <svg className="h-6 w-6 text-green-500 mr-3 flex-shrink-0" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M5 13l4 4L19 7" />
                                            </svg>
                                            <p className="text-gray-700">Everything in Free</p>
                                        </li>
                                        <li className="flex items-start">
                                            <svg className="h-6 w-6 text-green-500 mr-3 flex-shrink-0" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M5 13l4 4L19 7" />
                                            </svg>
                                            <p className="text-gray-700">Automated campaign setup</p>
                                        </li>
                                        <li className="flex items-start">
                                            <svg className="h-6 w-6 text-green-500 mr-3 flex-shrink-0" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M5 13l4 4L19 7" />
                                            </svg>
                                            <p className="text-gray-700">24/7 AI optimization</p>
                                        </li>
                                        <li className="flex items-start">
                                            <svg className="h-6 w-6 text-green-500 mr-3 flex-shrink-0" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M5 13l4 4L19 7" />
                                            </svg>
                                            <p className="text-gray-700">Performance analytics</p>
                                        </li>
                                        <li className="flex items-start">
                                            <svg className="h-6 w-6 text-gray-400 mr-3 flex-shrink-0" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M12 8v4m0 4v.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                                            </svg>
                                            <p className="text-gray-700">Multi-platform support (Coming Soon)</p>
                                        </li>
                                    </ul>
                                    <div className="mt-10">
                                        <a
                                            href="/register"
                                            className="block w-full text-center rounded-lg bg-indigo-600 px-6 py-3 text-base font-medium text-white hover:bg-indigo-700 shadow-lg"
                                        >
                                            Deploy Your Campaign
                                        </a>
                                    </div>
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
                                    Get answers to common questions about how MediaAgent works.
                                </p>
                            </div>

                            <div className="space-y-4">
                                {[
                                    {
                                        question: "Do I need a credit card to get started?",
                                        answer: "No! You can generate unlimited creative collateral with our Free plan—no credit card required. Only when you're ready to deploy campaigns to Google Ads do you need to add a payment method."
                                    },
                                    {
                                        question: "What advertising platforms do you support?",
                                        answer: "Currently, we support Google Ads (available now). We're actively building integrations with Meta Ads, Microsoft Advertising, and Reddit Ads—coming soon. All of these will be available at no extra cost with your Pro plan."
                                    },
                                    {
                                        question: "Can I cancel my plan anytime?",
                                        answer: "Absolutely. Cancel anytime, no questions asked. Your campaigns will continue running until the end of your billing cycle, and we'll stop charging you immediately. You keep your account data and creative assets."
                                    },
                                    {
                                        question: "How much will my actual ad spend be?",
                                        answer: "You control your ad spend completely. The $200/month is our platform fee. Your ad spend depends on your budget, industry, and campaign settings—you set the limits, and we optimize what you spend daily to maximize ROI."
                                    },
                                    {
                                        question: "Is my data and campaign data safe?",
                                        answer: "Yes. We encrypt all data in transit and at rest. We only access your ad accounts to create and optimize campaigns on your behalf—we never store credentials locally. Check out our Privacy Policy and Terms of Service for full details."
                                    },
                                    {
                                        question: "How does the AI optimization work?",
                                        answer: "Our AI continuously monitors your campaign performance and tests variations of your ad copy and creative automatically. We identify top performers and allocate more budget to what works—24/7, without your intervention."
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
